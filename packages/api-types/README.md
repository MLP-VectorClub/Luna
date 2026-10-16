# MLP VectorClub API type definitions

These type definitions are generated based on the MLP Vector Club's public OpenAPI specifications.

They can be utilized in any TypeScript-based applications to refer to the latest types provided by the application.

## Usage

Make sure TypeScript is configured in your project, then simply install the type definitions package with your favourite package manager:

```shell
# Via NPM
$ npm install @went.tf/mlp-vectorclub-api-types
# Via YARN
$ yarn add @went.tf/mlp-vectorclub-api-types
```

In your TypeScript code, simply import the necessary types from the package:

```ts
import { Appearance } from '@went.tf/mlp-vectorclub-api-types';

const value: Appearance = {/* … */};
```

## Building and publishing

The types are built from `docs/openapi/api-docs.json` in this repository (`php artisan openapi:sync` keeps it current): run `pnpm install --frozen-lockfile && pnpm build` (the pnpm workspace root is the repository root, `pnpm` is the package manager of the Luna repository). The output goes to `dist`, together with a copy of the schema JSON.

Every push to `main` that changes the OpenAPI document publishes a new version through the `Publish API types` workflow, which can also be started by hand. The version is `major.minor` from `package.json` plus the workflow run number as the patch number, so raise `major.minor` yourself when the API changes in a breaking way. Publishing uses npm trusted publishing (OIDC) through `npm publish`, no token is stored.
