// Checks .railway/railway.ts without a Railway account (CI and local):
// evaluates it with Railway's SDK and checks what it describes.
//   npm install --prefix .railway && npx --prefix .railway tsc -p .railway --noEmit false --outDir .railway/.out && node .railway/check.mjs
import { existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRailwayContext, project } from 'railway/iac';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');
const { default: program, partial } = await import(join(here, '.out', 'railway.js'));
const def = await program(createRailwayContext({ projectName: 'check' }), project);
const resources = def.resources.flat();
const problems = [];
const services = resources.filter((r) => r.type === 'service');

const expected = ['api', 'queue', 'scheduler', 'socket', 'console'];
for (const name of expected) if (!services.some((s) => s.name === name)) problems.push(`missing service ${name}`);
for (const name of ['MySQL', 'Redis']) if (!resources.some((r) => r.type === 'database' && r.name === name)) problems.push(`missing database ${name}`);
if (!partial) problems.push('no partial: applying could remove other resources in the project');

for (const s of services) {
  if (s.source?.repo !== 'baimasonga/fleetbase') problems.push(`${s.name}: source is ${s.source?.repo}`);
  if (s.build?.builder !== 'DOCKERFILE') problems.push(`${s.name}: not built from a Dockerfile (Railpack can't build this repository)`);
  const dockerfile = join(root, s.source?.rootDirectory || '', s.build?.dockerfilePath || '');
  if (!existsSync(dockerfile)) problems.push(`${s.name}: ${dockerfile} does not exist`);
  for (const [key, value] of Object.entries(s.variables || {})) {
    if (value?.type === 'literal' && /secret|password|key/i.test(key) && value.value) problems.push(`${s.name}: ${key} has a literal value; secrets must be shared variables`);
  }
  console.log(`ok ${s.name}: ${s.source?.repo}@${s.source?.branch} ${s.source?.rootDirectory ? '/' + s.source.rootDirectory + ' ' : ''}${s.build?.dockerfilePath}`);
}

if (problems.length) {
  console.error(problems.join('\n'));
  process.exit(1);
}
console.log(`.railway/railway.ts describes ${services.length} services and ${resources.length - services.length} other resources.`);
