// TAAP — generador de la marca (brand-identity D1).
// Del maestro `taap-faucet.svg` rinde todos los formatos:
//   public/favicon.svg · public/favicon.ico (16/32/48) · public/apple-touch-icon.png (180)
//   mobile/ios/Taap/Assets.xcassets/AppIcon.appiconset/*.png (set iPhone canonico)
// Rasteriza con `rsvg-convert` y arma el .ico con ImageMagick (`magick`).
// Uso: node generate.mjs   (desde cualquier cwd; las rutas se resuelven al repo)

import { readFileSync, writeFileSync, mkdirSync } from 'node:fs'
import { execSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const here = path.dirname(fileURLToPath(import.meta.url))
const repo = path.resolve(here, '../../..') // orchestrator/assets/brand -> raiz
const orchestrator = path.resolve(here, '../..')
const iosAssets = path.resolve(repo, 'mobile/ios/Taap/Assets.xcassets/AppIcon.appiconset')

// Paleta (brand-identity D3): los valores de la tabla de tokens.
const AQUA_600 = '#1E7F7A'
const AQUA_200 = '#ACE4DE'
const INK = '#125E7A'
const WHITE = '#FFFFFF'

const master = readFileSync(new URL('./taap-faucet.svg', import.meta.url), 'utf8')
const faucetD = master.match(/<path id="faucet" fill="[^"]*" d="([^"]*)"/)?.[1]
const dropD = master.match(/<path id="drop" fill="[^"]*" d="([^"]*)"/)?.[1]
if (!faucetD || !dropD) {
    console.error('maestro: falta el path "faucet" o "drop" — no se puede generar')
    process.exit(1)
}

/** Variante del glifo. `bg` rellena el lienzo completo (teja full-bleed). */
const variant = (faucetFill, dropFill, bg = null) =>
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024">` +
    (bg ? `<rect width="1024" height="1024" fill="${bg}"/>` : '') +
    `<path fill="${faucetFill}" d="${faucetD}"/><path fill="${dropFill}" d="${dropD}"/></svg>`

const FAVICON = variant(AQUA_600, INK) // glifo bicolor, fondo transparente
const TILE = variant(WHITE, AQUA_200, AQUA_600) // teja: glifo blanco + gota clara

const tmp = path.join(orchestrator, 'assets/brand/.gen')
mkdirSync(tmp, { recursive: true })
mkdirSync(iosAssets, { recursive: true })

const run = (cmd) => execSync(cmd, { stdio: 'pipe' })
const raster = (svg, size, outPng) => {
    const svgPath = path.join(tmp, path.basename(outPng) + '.svg')
    writeFileSync(svgPath, svg)
    run(`rsvg-convert -w ${size} -h ${size} "${svgPath}" -o "${outPng}"`)
}

let n = 0
const log = (p) => { console.log(`  ${p}`); n++ }

console.log('favicon:')
writeFileSync(path.join(orchestrator, 'public/favicon.svg'), FAVICON)
log('public/favicon.svg')
for (const s of [16, 32, 48]) raster(FAVICON, s, path.join(tmp, `ico-${s}.png`))
run(`magick "${tmp}/ico-16.png" "${tmp}/ico-32.png" "${tmp}/ico-48.png" "${path.join(orchestrator, 'public/favicon.ico')}"`)
log('public/favicon.ico (16/32/48)')
raster(TILE, 180, path.join(orchestrator, 'public/apple-touch-icon.png'))
log('public/apple-touch-icon.png (180, teja)')

console.log('app icon (teja):')
writeFileSync(
    path.join(iosAssets, 'Contents.json'),
    JSON.stringify({
        images: [
            { filename: 'icon-20@2x.png', idiom: 'iphone', scale: '2x', size: '20x20' },
            { filename: 'icon-20@3x.png', idiom: 'iphone', scale: '3x', size: '20x20' },
            { filename: 'icon-29@2x.png', idiom: 'iphone', scale: '2x', size: '29x29' },
            { filename: 'icon-29@3x.png', idiom: 'iphone', scale: '3x', size: '29x29' },
            { filename: 'icon-40@2x.png', idiom: 'iphone', scale: '2x', size: '40x40' },
            { filename: 'icon-40@3x.png', idiom: 'iphone', scale: '3x', size: '40x40' },
            { filename: 'icon-60@2x.png', idiom: 'iphone', scale: '2x', size: '60x60' },
            { filename: 'icon-60@3x.png', idiom: 'iphone', scale: '3x', size: '60x60' },
            { filename: 'icon-76@2x.png', idiom: 'iphone', scale: '2x', size: '76x76' },
            { filename: 'icon-83.5@2x.png', idiom: 'iphone', scale: '2x', size: '83.5x83.5' },
        ],
        info: { author: 'taap-brand', version: 1 },
    }, null, 4) + '\n'
)
log('AppIcon.appiconset/Contents.json')
for (const [name, size] of [
    ['icon-20@2x.png', 40], ['icon-20@3x.png', 60],
    ['icon-29@2x.png', 58], ['icon-29@3x.png', 87],
    ['icon-40@2x.png', 80], ['icon-40@3x.png', 120],
    ['icon-60@2x.png', 120], ['icon-60@3x.png', 180],
    ['icon-76@2x.png', 152], ['icon-83.5@2x.png', 167],
]) {
    raster(TILE, size, path.join(iosAssets, name))
    log(`AppIcon ${name} (${size}px)`)
}

// Limpia el directorio temporal.
execSync(`rm -rf "${tmp}"`)
console.log(`ok: ${n} formatos generados`)
