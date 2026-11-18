#!/usr/bin/env node
// Collects the licenses of all npm packages bundled into the frontend (the "dependencies" of
// frontend/package.json and everything they depend on) into one text file. MIT, BSD and ISC
// require that copyright notice and license text accompany every copy, and the release archive
// contains the bundled code – so this file ships with it.
//
//   node scripts/third-party-licenses.mjs [output]   (default: public/THIRD-PARTY-LICENSES.txt)
//
// Runs automatically after "npm run build" (see frontend/package.json).
import { readFileSync, writeFileSync, existsSync, readdirSync } from 'node:fs'
import { join, dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root     = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const frontend = join(root, 'frontend')
const out      = resolve(process.argv[2] ?? join(root, 'public', 'THIRD-PARTY-LICENSES.txt'))

const readJson = file => JSON.parse(readFileSync(file, 'utf8'))

// Resolve a package the way Node does: nested node_modules first, then upwards
function findPackage(name, fromDir) {
  for (let dir = fromDir; dir.startsWith(frontend); dir = dirname(dir)) {
    const candidate = join(dir, 'node_modules', name)
    if (existsSync(join(candidate, 'package.json'))) return candidate
    if (dir === frontend) break
  }
  return null
}

const packages = new Map()   // "name@version" → info
function collect(name, fromDir) {
  const dir = findPackage(name, fromDir)
  if (!dir) throw new Error(`Package ${name} not found (run npm ci in frontend/)`)
  const pkg = readJson(join(dir, 'package.json'))
  const key = `${pkg.name}@${pkg.version}`
  if (packages.has(key)) return
  const licenseFile = readdirSync(dir).find(f => /^(licen[cs]e|copying)(\..*)?$/i.test(f))
  packages.set(key, {
    name: pkg.name,
    version: pkg.version,
    license: typeof pkg.license === 'string' ? pkg.license : (pkg.license?.type ?? pkg.licenses?.map(l => l.type).join(' OR ') ?? 'UNKNOWN'),
    homepage: pkg.homepage ?? (typeof pkg.repository === 'string' ? pkg.repository : pkg.repository?.url) ?? '',
    text: licenseFile ? readFileSync(join(dir, licenseFile), 'utf8').trim() : null,
  })
  for (const dep of Object.keys(pkg.dependencies ?? {})) collect(dep, dir)
}

const app = readJson(join(frontend, 'package.json'))
for (const dep of Object.keys(app.dependencies ?? {})) collect(dep, frontend)

const list = [...packages.values()].sort((a, b) => a.name.localeCompare(b.name))
const missing = list.filter(p => !p.text)
const rule = '='.repeat(78)
const text = [
  'Avunculus – third-party software',
  '',
  'Avunculus itself is licensed under the GNU Affero General Public License v3 or later (see LICENSE).',
  'The web interface includes the following open-source packages. Their copyright notices and',
  'license terms are reproduced below as required by their licenses.',
  '',
  ...list.map(p => `  ${p.name} ${p.version} – ${p.license}`),
  '',
  ...list.flatMap(p => [
    rule,
    `${p.name} ${p.version} – ${p.license}`,
    p.homepage ? p.homepage.replace(/^git\+/, '') : '',
    rule,
    '',
    p.text ?? `(No license file included in the package. License according to its package.json: ${p.license}.)`,
    '',
  ]),
].join('\n')

writeFileSync(out, text)
console.log(`${out}: ${list.length} packages` + (missing.length ? `, without license file: ${missing.map(p => p.name).join(', ')}` : ''))
