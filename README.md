# Shakewell Connector for Klaviyo and Gravity Forms

Free, GPL-2.0-or-later WordPress plugin. Adds people who submit selected Gravity Forms forms to a Klaviyo list, with consent. Off by default. Setup, mapping and the data sent are in `readme.txt`.

```sh
composer install && npm ci
npm run env:start   # port 8908, tests on 8909, needs Docker
npm test
composer lint
npm run plugin-check
npm run env:stop
```

Gravity Forms is paid and not available in CI, so the tests drive the `gform_after_submission` contract with stubs for the entry meta functions.
