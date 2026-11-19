# BroCode_Poppy

Personal Agent Protocol (Poppy) 1.0 discovery for Magento 2. Publishes
`/.well-known/poppy.json` per store, in the website-only shape (no `auth`), and can
point Personal Agents at an existing UCP profile through the `ucp` extension.

It implements no UCP itself. Pair it with a module that serves `/.well-known/ucp`
(tested with `angeo/module-ucp` + `angeo/module-ucp-catalog`).

## Install

```bash
ddev composer require brocode/module-poppy
ddev exec php bin/magento module:enable BroCode_Poppy
ddev exec php bin/magento setup:upgrade
```

## Configure

Stores → Configuration → General → Personal Agent Protocol (Poppy):

| Path | Default | Effect |
|---|---|---|
| `brocode_poppy/general/enabled` | 0 | Serve the document; 404 otherwise |
| `brocode_poppy/general/organization_name` | empty | Falls back to Store Information name, then the store's frontend name |
| `brocode_poppy/general/logo_uri` | empty | Published only when https |
| `brocode_poppy/extensions/ucp` | 0 | Adds `"extensions": {"ucp": [{"version": "1"}]}` |

`organization.domain` comes from the store's secure base URL (lowercase, port only when not 443).
Without an https base URL nothing is published. The response is public and cached for 300 s;
flush the cache after a config change.

## Verify

```bash
curl -s https://<host>/.well-known/poppy.json | jq .
node <kit>/validate/index.ts check https://<host> --milestone foundation
```
