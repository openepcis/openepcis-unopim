<?php

/*
 * Copyright (c) 2026 benelog GmbH & Co. KG
 * SPDX-License-Identifier: MIT
 */

namespace OpenEPCIS\UnoPim\Console;

use Illuminate\Console\Command;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeFamilyProxy;
use Webkul\ProductPassport\Http\Controllers\PublicationController;
use Webkul\ProductPassport\Models\PassportTemplate;
use Webkul\ProductPassport\Models\PassportTemplateField;
use Webkul\ProductPassport\Models\PassportTemplateSection;

/**
 * Installs a regulation's passport into UnoPim as one of its own templates.
 *
 * UnoPim's template mechanism is good and its supplied templates are thin — the
 * battery one has twenty-six fields where the regulation names about two
 * hundred, and a single `dpp_carbon_footprint` where it asks for six different
 * carbon figures. That is not a criticism of UnoPim; assembling a regulation's
 * data points is work that has nothing to do with building a PIM.
 *
 * It is, however, exactly the work already done in our extensions. So this
 * hands it over. Every field is a curated term, which makes the mapping between
 * this PIM and the catalog exact rather than a guess, and each arrives with two
 * things a hand-built template rarely has:
 *
 *   * a tier the regulation fixes — Annex XIII(1) is public, XIII(2) is for
 *     legitimate interest, XIII(3) is for authorities — carried with the
 *     article it comes from, so nobody has to defend it from memory
 *   * a granularity, so a value measured for one battery is not written over
 *     the model it belongs to
 *
 * Idempotent by construction: everything is matched on its code and updated in
 * place. Running it twice changes nothing, and running it after an extension
 * gains a data point adds that one field.
 *
 * Nothing is deleted, ever. A field somebody removed from the template on
 * purpose stays removed, and a field they added by hand stays theirs.
 */
class InstallPassportTemplate extends Command
{
    // The blueprint list is NOT spelled out here. It was, and it went stale: the
    // help named four while five shipped, so `gs1-product` -- the one bound to
    // the default family -- looked like it did not exist. The authoritative list
    // is the Resources/blueprints directory, and handle() prints it on an
    // unknown name.
    protected $signature = 'openepcis:passport-template
                            {blueprint : Which regulation — pass an unknown name to list them}
                            {--family=* : Attribute family codes that should publish it}
                            {--dry-run : Say what would change and change nothing}';

    protected $description = 'Install an OpenEPCIS regulation passport as a UnoPim template';

    /** Our access levels are the regulation's audiences; UnoPim names its tiers the same three. */
    private const TIERS = ['consumer', 'operator', 'authority'];

    /**
     * UnoPim's own identifier roles (Enums/PassportFieldRole). Exactly one
     * field per role and per template: the GTIN identifies the model, the
     * model identifier the design it belongs to, the batch the lot.
     */
    private const array ROLES = ['gtin', 'model', 'batch'];

    public function handle(): int
    {
        $name = (string) $this->argument('blueprint');
        $path = __DIR__.'/../Resources/blueprints/'.$name.'.json';

        if (! is_file($path)) {
            $this->error("No blueprint '$name'. Available: "
                .implode(', ', array_map(
                    fn ($f) => basename($f, '.json'),
                    glob(__DIR__.'/../Resources/blueprints/*.json'))));

            return self::FAILURE;
        }

        $blueprint = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if ($offenders = $this->badCodes($blueprint)) {
            $this->error("Blueprint '$name' carries codes UnoPim will not accept:");
            foreach ($offenders as $offender) {
                $this->line('  '.$offender);
            }
            $this->comment('Allowed: '.self::CODE_PATTERN.' — letters first, then letters,'
                .' digits or underscores. A hyphen is not allowed.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            '%s: %d fields from %s',
            $blueprint['code'], count($blueprint['fields']), $blueprint['source']
        ));

        if ($dryRun) {
            $this->comment('Dry run — nothing will be written.');
        }

        $template = $this->template($blueprint, $dryRun);
        $sections = $this->sections($blueprint, $template, $dryRun);

        $created = 0;
        $updated = 0;

        foreach ($blueprint['fields'] as $position => $field) {
            $attribute = $this->attribute($field, $dryRun);

            if ($dryRun) {
                $created++;
                continue;
            }

            $existing = PassportTemplateField::where('passport_template_id', $template->id)
                ->where('code', $field['code'])->first();

            PassportTemplateField::updateOrCreate(
                ['passport_template_id' => $template->id, 'code' => $field['code']],
                [
                    'passport_template_section_id' => $sections[$field['section']] ?? null,
                    'source_type'  => 'attribute',
                    'attribute_id' => $attribute?->id,
                    'tier'         => in_array($field['tier'], self::TIERS, true)
                        ? $field['tier'] : 'consumer',
                    // The identifier roles are what UnoPim renders its passport
                    // header from. A template without them installs 200 correct
                    // data points above a passport that cannot say which article
                    // it describes.
                    'role'         => in_array($field['role'] ?? null, self::ROLES, true)
                        ? $field['role'] : null,
                    'is_required'  => (bool) ($field['required'] ?? false),
                    'position'     => $position + 1,
                ]
            );

            $this->label($template, $field);

            $existing ? $updated++ : $created++;
        }

        $this->line(sprintf('  fields: %d created, %d updated, 0 removed', $created, $updated));
        $this->line('  Nothing is ever deleted: a field you removed stays removed.');

        $this->families($template, $dryRun);

        // Installing into a switched-off feature succeeds and shows nothing:
        // no Passports entry in the menu, no header on a product. Saying so
        // here costs one query and saves the hunt for a template that is
        // demonstrably in the database and nowhere on the screen.
        if (! PublicationController::featureEnabled()) {
            $this->warn('  The product passport feature is off, so none of this is visible.');
            $this->warn('  Turn it on under Configuration -> Catalog -> Product Passport.');
        }

        return self::SUCCESS;
    }

    /**
     * The name a person reads on the passport page.
     *
     * <p>Without it UnoPim renders the field CODE in brackets — `[netWeight]`,
     * `[inPackageDepth]` — because a template field carries its own
     * translations and does not borrow the attribute's. A passport that names
     * its rows in curly-bracketed camelCase looks like a debug view, and the
     * label is already in the blueprint: it is the term's own rdfs:label.
     *
     * <p>Written for every locale this installation has. The vocabularies are
     * English, so every locale gets the English label rather than none —
     * a name in the wrong language beats a code in brackets, and a merchant
     * can edit it.
     */
    private function label(?PassportTemplate $template, array $field): void
    {
        // Scoped to THIS template: the same code lives in several of them —
        // `gtin` is in all five — and a lookup by code alone labels whichever
        // row happened to be first, five times over.
        $row = $template === null ? null : PassportTemplateField::where('passport_template_id', $template->id)
            ->where('code', $field['code'])->first();

        if ($row === null || ($field['label'] ?? '') === '') {
            return;
        }

        foreach (core()->getAllLocales() as $locale) {
            $translation = $row->translations()->firstOrNew(['locale' => $locale->code]);

            // Never overwrite a label somebody edited: the install is
            // repeatable, and a repeatable install that undoes corrections is
            // a trap rather than a convenience.
            if ($translation->exists && ($translation->label ?? '') !== '') {
                continue;
            }

            $translation->label = $field['label'];
            $translation->save();
        }
    }

    /**
     * Binds the template to the attribute families that should publish it.
     *
     * <p>The --family option was declared from the start and never read. That is
     * worse than not having it: the command accepted `--family=default`, said
     * nothing, and left the template bound to nothing -- and an unbound template
     * publishes no passport for any product. It was found on 2026-09-03, when
     * demo had all five templates installed, every field sourced, and still not
     * one passport, because the binding row was simply absent.
     *
     * <p>A family publishes AT MOST ONE template: passport_template_families
     * carries UNIQUE(attribute_family_id), not a composite key. So binding a
     * family that already belongs to another template is not an addition, it is
     * a move -- and this command does not take things away. It says which
     * template holds the family and leaves it alone; rebinding is a decision for
     * whoever knows why the other template has it, and it is one click in
     * Catalog -> Passports -> Templates.
     *
     * <p>Learned the hard way: syncWithoutDetaching walks straight into that
     * unique constraint and fails the whole command with an SQLSTATE 23505,
     * after the fields are already written.
     *
     * <p>An unknown code is a warning and not a failure. Installing the template
     * has already succeeded at this point, and failing here would leave the
     * caller unsure whether to run it again.
     */
    private function families(?PassportTemplate $template, bool $dryRun): void
    {
        /** @var array<int,string> $codes */
        $codes = array_values(array_unique(array_filter((array) $this->option('family'))));

        if ($codes === []) {
            return;
        }

        if ($dryRun) {
            $this->line('  families: would bind '.implode(', ', $codes));

            return;
        }

        if ($template === null) {
            return;
        }

        $families = AttributeFamilyProxy::modelClass()::query()
            ->whereIn('code', $codes)
            ->get()
            ->keyBy('code');

        foreach (array_diff($codes, $families->keys()->all()) as $missing) {
            $this->warn("  No attribute family '$missing' — not bound.");
        }

        $bound = [];

        foreach ($families as $code => $family) {
            $holder = PassportTemplate::query()
                ->whereHas('families', fn ($query) => $query->whereKey($family->id))
                ->first();

            if ($holder !== null) {
                if ($holder->id !== $template->id) {
                    $this->warn("  Family '$code' already publishes '{$holder->code}' — left as it is.");
                }

                continue;
            }

            $template->families()->attach($family->id);
            $bound[] = $code;
        }

        if ($bound !== []) {
            $this->line('  families: bound '.implode(', ', $bound));
        }
    }

    /**
     * The pattern PassportTemplateRequest enforces on every code — the
     * template's own, each section's and each field's. Kept here verbatim
     * rather than referenced, because the request class is UnoPim's and a
     * blueprint has to be judged before anything is written.
     */
    private const CODE_PATTERN = '/^[a-zA-Z]+[a-zA-Z0-9_]+$/';

    /**
     * Codes this blueprint would install that UnoPim would later refuse.
     *
     * WHY THIS RUNS BEFORE THE INSTALL AND NOT AFTER: installing does not
     * validate. The template appears, opens, looks finished — and the
     * validation fires on SAVE, so the first person to edit anything about it
     * gets the refusal, with no hint that the blueprint was at fault. That is
     * exactly how five templates with hyphenated codes lived on demo and dev
     * until 2026-09-03, and how three hyphenated SECTION codes in battery
     * survived a first round of fixing the template codes: nothing complained
     * at install time, twice.
     *
     * @return list<string>
     */
    private function badCodes(array $blueprint): array
    {
        $offenders = [];

        if (! preg_match(self::CODE_PATTERN, (string) ($blueprint['code'] ?? ''))) {
            $offenders[] = 'template code: '.($blueprint['code'] ?? '(missing)');
        }

        $sections = [];
        foreach ($blueprint['fields'] ?? [] as $field) {
            if (! preg_match(self::CODE_PATTERN, (string) ($field['code'] ?? ''))) {
                $offenders[] = 'field code: '.($field['code'] ?? '(missing)');
            }
            if (isset($field['section'])) {
                $sections[$field['section']] = true;
            }
        }
        foreach (array_keys($sections) as $section) {
            if (! preg_match(self::CODE_PATTERN, (string) $section)) {
                $offenders[] = 'section code: '.$section;
            }
        }

        return array_values(array_unique($offenders));
    }

    private function template(array $blueprint, bool $dryRun): ?PassportTemplate
    {
        if ($dryRun) {
            return null;
        }

        return PassportTemplate::firstOrCreate(
            ['code' => $blueprint['code']],
            ['is_enabled' => true]
        );
    }

    /** @return array<string,int> section code -> id */
    private function sections(array $blueprint, ?PassportTemplate $template, bool $dryRun): array
    {
        $codes = array_values(array_unique(array_column($blueprint['fields'], 'section')));
        sort($codes);

        // Identity opens the passport. Alphabetical order would file it between
        // `general` and `materials`, which reads as an afterthought for the one
        // section that says which article the other two hundred rows describe.
        if (($at = array_search('identity', $codes, true)) !== false) {
            unset($codes[$at]);
            array_unshift($codes, 'identity');
            $codes = array_values($codes);
        }

        if ($dryRun || $template === null) {
            $this->line('  sections: '.implode(', ', $codes));

            return [];
        }

        $ids = [];
        foreach ($codes as $position => $code) {
            // updateOrCreate, not firstOrCreate: a section installed before
            // identity existed keeps its old position forever otherwise, and
            // the header ends up filed between circularity and materials.
            $ids[$code] = PassportTemplateSection::updateOrCreate(
                ['passport_template_id' => $template->id, 'code' => $code],
                ['position' => $position + 1]
            )->id;
        }

        return $ids;
    }

    /**
     * The attribute a field reads from, created if this installation has none.
     *
     * The code is the curated term itself. That is what makes the mapping to
     * the catalog exact: no alias table, no near-match, the field and the term
     * are the same string.
     */
    private function attribute(array $field, bool $dryRun): ?Attribute
    {
        // A term UnoPim already has an attribute for points at that attribute.
        // Creating `netWeight` beside UnoPim's own `weight` gives a merchant two
        // fields for one fact, and whichever the connector reads, the other is
        // wrong from the moment somebody edits it.
        $code = $this->aliases()[$field['code']]['attribute'] ?? $field['code'];

        $existing = Attribute::where('code', $code)->first();

        if ($existing || $dryRun) {
            return $existing;
        }

        return Attribute::create([
            'code'             => $code,
            'type'             => $this->inputType($field['range'] ?? ''),
            'validation'       => $this->validation($field['range'] ?? ''),
            'is_required'      => false,
            'is_unique'        => false,
            // Translatable where the term's own range says the value is text in
            // a language. Everything else is the same in every market, and
            // asking a merchant to type a number once per locale is how a
            // passport ends up with four different weights.
            'value_per_locale'  => ($field['range'] ?? '') === 'rdf:langString',
            'value_per_channel' => false,
            'labels'           => ['en_US' => $field['label']],
        ]);
    }

    /**
     * Terms UnoPim already carries, and the attribute that carries each.
     *
     * Curated by hand and every entry says why, because each is a claim that
     * two names mean the same thing. Where UnoPim has nothing equivalent there
     * is no entry: an honest new attribute beats an alias to a near-enough one.
     *
     * @return array<string,array{attribute:string,why:string}>
     */
    private function aliases(): array
    {
        static $aliases = null;

        if ($aliases === null) {
            $path = __DIR__.'/../Resources/aliases/unopim.json';
            $aliases = is_file($path)
                ? (json_decode(file_get_contents($path), true)['aliases'] ?? [])
                : [];
        }

        return $aliases;
    }

    /**
     * The input a merchant is given, from what the vocabulary says the value is.
     *
     * A text box for everything is how a passport fills with strings nobody can
     * compute with — "about 4 kg", "yes (see note)", "2024ish".
     */
    private function inputType(string $range): string
    {
        return match (true) {
            $range === 'xsd:boolean'   => 'boolean',
            $range === 'xsd:date'      => 'date',
            $range === 'xsd:dateTime'  => 'datetime',
            $range === 'xsd:anyURI'    => 'text',
            $range === 'rdf:langString' => 'textarea',
            str_contains($range, 'QuantitativeValue') => 'measurement',
            in_array($range, ['xsd:decimal', 'xsd:integer', 'xsd:float'], true) => 'text',
            default => 'text',
        };
    }

    private function validation(string $range): ?string
    {
        return match ($range) {
            'xsd:decimal', 'xsd:float' => 'decimal',
            'xsd:integer' => 'numeric',
            'xsd:anyURI'  => 'url',
            default       => null,
        };
    }
}
