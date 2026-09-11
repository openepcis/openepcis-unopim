<?php

/*
 * Copyright (c) 2026 benelog GmbH & Co. KG
 * SPDX-License-Identifier: MIT
 */

namespace OpenEPCIS\UnoPim\Console;

use Illuminate\Console\Command;
use Webkul\Attribute\Models\Attribute;
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
    protected $signature = 'openepcis:passport-template
                            {blueprint : Which regulation — battery, textile, ppwr, electronics}
                            {--family=* : Attribute families that should publish it}
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
