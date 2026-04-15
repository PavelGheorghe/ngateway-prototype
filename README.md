# Puntu Symfony App (Docker + service-separated API)

Symfony migration of the original `puntu-api` flow:
- Domain check / create / inquire / renew / delete / modify
- Domain inquire + contact ownership validation
- Contact create
- Zone create / inquire / modify / delete
- Frontend form rendered with Twig

## Run with Docker

```bash
docker compose up --build
```

Open [http://localhost:8000](http://localhost:8000).

## Environment

Set in shell or `.env.local`:

- `CORE_MEMBER_ID` (default `CORE-703`)
- `CORE_ATP`
- `CORE_CLIENT_NAME` (default `puntu-symfony`)
- `CORE_PRODUCTION` (`true`/`false`)
- `CORE_PROVIDER_CHAIN_SPEC`
- `CORE_PROVIDER_CHAIN_TYPE` (default `billing`)
- `CORE_DEFAULT_NS1`, `CORE_DEFAULT_NS2`
- `CORE_DOMAIN_CREATE_NS_MANDATORY_REGISTRIES` (comma-separated `registry.id` values, e.g. `.com`; empty means nameservers are optional for `domain.create`)
- `CORE_SKIP_PROVIDER_CHAIN` (`true`/`false`)
- `CORE_DEBUG_REQUEST` (`true`/`false`)

## Architecture

- `src/Controller/ApiController.php` -> HTTP routes
- `src/Service/CoreGatewayHttpService.php` -> base CORE HTTP transport (Guzzle, preconfigured `id`/`atp`)
- `src/Service/CoreGatewayClient.php` -> CORE payload builder/parser
- `src/Service/CoreGatewayHelper.php` -> response/payload helpers
- `src/Service/ContactService.php` -> contact operations
- `src/Service/DomainService.php` -> domain operations
- `src/Service/ZoneService.php` -> zone operations

## New endpoint: inquire domain by contact

Use this endpoint to verify whether a given `contactId` is linked to a domain from `domain.inquire` response contacts.

`POST /api/domain/inquire-by-contact`

Request body:

```json
{
  "domainName": "gheorghe.eus",
  "registryId": ".eus",
  "contactId": "abc123"
}
```

Response includes:

- `contactLinked` -> `true` when `contactId` matches `contact.N.id` in CORE response
- `matchedRoles` -> matched contact roles (example: `registrant`, `admin`)
# ngateway-prototype
