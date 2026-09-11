# OpenEPCIS for UnoPim

| | |
|---|---|
| **Licence** | MIT |
| **UnoPim** | 3.0 |
| **PHP** | 8.4 or newer (what UnoPim 3.0 itself requires) |
| **Needs** | a reachable OpenEPCIS connector (the bridge); nothing else is installed into the PIM |

The UnoPim half of the connector. The Java side reads UnoPim and publishes into
the DDM catalog; this side puts what the platform knows back in front of the
person maintaining the product, in the screen they are already in.

Three things, and each exists because leaving it out costs a merchant something
concrete:

**Classification by words.** A GPC brick is eight digits, and nobody knows them.
A plain text field for one gets a wrong code or an empty one — and a wrong
classification is worse, because it looks filled in. The field searches the GS1
classification and stores the code it found.

**Drawing a number.** A GTIN typed by hand is a GTIN somebody else may already
be using, or one with a bad check digit that fails at the till. The button draws
the next number from the tenant's own allocated range, through the resolver that
keeps that ledger, so no two products can be given the same one.

**Saying what the platform thinks.** The connector already writes its verdict
back into `ddm_*` attributes. Read as a row of raw attribute values they mean
little; shown as one panel they answer the only question a merchant has — is
this product's identity in order, and if not, is that something I did or
something on our side.

## Installation

The package is loaded by Laravel's package discovery, so an installation is a
composer require plus a cache clear. It is not on Packagist yet, so the
repository has to be named once:

```bash
composer config repositories.openepcis vcs https://github.com/openepcis/openepcis-unopim
composer require openepcis/unopim-connector:dev-main
php artisan config:clear
```

Baking it into an image instead — a copy into `packages/`, the namespace and
the provider registered, `composer dump-autoload` — is the same installation
done once at build time, which is what a pod that may be rescheduled needs.

## Configuration

The bridge is usually reached over plain HTTP inside the same cluster, so the
connector's service name is all the configuration there is:

```env
OPENEPCIS_BRIDGE_URL=http://openepcis-connectors:8080
OPENEPCIS_TENANT=pim1
```

`OPENEPCIS_TENANT` is the name of the connection in the connector's own
configuration. The bridge answers for a tenant using that tenant's deposited
credentials, so it decides which catalog is asked and which company prefix a
drawn number comes from.

## Licence

This package is **MIT** (see `LICENSE`). It is installed into the merchant's
own UnoPim, so it has to carry a licence that lets the merchant use, modify
and redistribute it without asking; and UnoPim itself is MIT, so nothing more
restrictive is called for. The GS1 logic it displays stays on the other
side of the HTTP boundary, in the bridge, under the proprietary licence.
