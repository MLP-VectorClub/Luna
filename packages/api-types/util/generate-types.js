const fs = require('fs');
const path = require('path');
const rimraf = require('rimraf');

const outputDir = 'dist';
// Luna keeps the OpenAPI document in source control, the types are always built from the one next to this package
const schemaPath = path.join(__dirname, '..', '..', '..', 'docs', 'openapi', 'api-docs.json');

function toCamel(str) {
  return str.charAt(0).toUpperCase() + str.slice(1);
}

function operationTypeName(opId) {
  return toCamel(opId);
}

function getSuccessResponseBody(op, opId) {
  const responses = op.responses || {};
  for (const code of Object.keys(responses).sort()) {
    if (!code.startsWith('2')) continue;
    const resp = responses[code];
    const hasJson = resp.content?.['application/json'];
    if (hasJson) {
      return `operations['${opId}']['responses'][${code}]['content']['application/json']`;
    }
  }
  return null;
}

function generateIndex(schema) {
  const schemaNames = Object.keys(schema.components?.schemas || {});
  const lines = [
    '// MLP-VectorClub API type definitions',
    "import type { components, operations } from './generated';",
    '',
    '// Strips [] suffix from property keys (used for array query params like "types[]")',
    'type StripArraySuffix<T> = {',
    '  [K in keyof T as K extends `${infer Base}[]` ? Base : K]: T[K];',
    '};',
    '',
    '// Component schema types',
  ];

  for (const name of schemaNames) {
    lines.push(`export type ${name} = components['schemas']['${name}'];`);
  }

  lines.push('');
  lines.push('// Operation request and response types');

  for (const [, methods] of Object.entries(schema.paths || {})) {
    for (const [method, op] of Object.entries(methods)) {
      if (!['get', 'post', 'put', 'patch', 'delete'].includes(method)) continue;
      const opId = op.operationId;
      if (!opId) continue;

      const params = op.parameters || [];
      const queryParams = params.filter((p) => p.in === 'query');
      const pathParams = params.filter((p) => p.in === 'path');
      const hasBody = !!op.requestBody;

      // Request type
      const requestParts = [];
      if (hasBody) {
        requestParts.push(`NonNullable<operations['${opId}']['requestBody']>['content']['application/json']`);
      }
      if (pathParams.length > 0) {
        requestParts.push(`operations['${opId}']['parameters']['path']`);
      }
      if (queryParams.length > 0) {
        const hasArrayParam = queryParams.some((p) => p.name.endsWith('[]'));
        const queryExpr = `NonNullable<operations['${opId}']['parameters']['query']>`;
        requestParts.push(hasArrayParam ? `StripArraySuffix<${queryExpr}>` : queryExpr);
      }
      if (requestParts.length > 0) {
        lines.push(`export type ${opId}Request = ${requestParts.join(' & ')};`);
      }

      // Response types
      const responses = op.responses || {};
      const successCodes = Object.keys(responses)
        .filter((c) => c.startsWith('2'))
        .sort();
      if (successCodes.length > 0) {
        const primaryCode = successCodes[0];
        const primaryResp = responses[primaryCode];
        const hasJson = primaryResp.content?.['application/json'];
        const resultType = hasJson ? `operations['${opId}']['responses'][${primaryCode}]['content']['application/json']` : 'undefined';
        lines.push(`export type ${opId}Result = ${resultType};`);

        // Additional 2xx codes
        for (const code of successCodes.slice(1)) {
          const resp = responses[code];
          const hasJsonAlt = resp.content?.['application/json'];
          const altType = hasJsonAlt ? `operations['${opId}']['responses'][${code}]['content']['application/json']` : 'undefined';
          lines.push(`export type ${opId}${code} = ${altType};`);
        }
      }
    }
  }

  return lines.join('\n') + '\n';
}

(async function () {
  if (fs.existsSync(outputDir)) {
    console.log('Build output directory already exists, clearing…');
    rimraf.sync(outputDir);
  }

  console.log('Creating output folder…');
  fs.mkdirSync(outputDir, { recursive: true });

  const schema = JSON.parse(fs.readFileSync(schemaPath, 'utf8'));
  console.log(`API schema read from ${schemaPath}`);

  console.log('Writing schema file…');
  fs.writeFileSync(path.join(outputDir, 'schema.json'), JSON.stringify(schema));
  console.log(`Written source OpenAPI schema file to dist/schema.json`);

  console.log('Generating raw types with openapi-typescript…');
  const openapiTS = (await import('openapi-typescript')).default;
  const { astToString } = await import('openapi-typescript');
  const ast = await openapiTS(schema);
  const rawTypes = astToString(ast);
  fs.writeFileSync(path.join(outputDir, 'generated.d.ts'), rawTypes);
  console.log('Written raw types to dist/generated.d.ts');

  console.log('Generating index.d.ts…');
  fs.writeFileSync(path.join(outputDir, 'index.d.ts'), generateIndex(schema));
  console.log('Written API type definitions to dist/index.d.ts');
})();
