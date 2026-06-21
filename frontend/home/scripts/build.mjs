import { spawn } from 'node:child_process'
import { cp, mkdir, rm } from 'node:fs/promises'
import os from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const scriptDir = path.dirname(fileURLToPath(import.meta.url))
const projectRoot = path.resolve(scriptDir, '..')
const nodeBin = process.execPath

const vueTscBin = path.join(projectRoot, 'node_modules', 'vue-tsc', 'bin', 'vue-tsc.js')
const viteBin = path.join(projectRoot, 'node_modules', 'vite', 'bin', 'vite.js')

const tempRoot = path.join(os.tmpdir(), `xapay-home-build-${process.pid}`)
const tempProjectRoot = path.join(tempRoot, 'workspace')

function isAsciiOnly(value) {
  return /^[\x00-\x7F]*$/.test(value)
}

function run(command, args, cwd) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, {
      cwd,
      stdio: 'inherit',
      shell: false,
    })

    child.on('error', reject)
    child.on('exit', (code) => {
      if (code === 0) {
        resolve()
        return
      }

      reject(new Error(`Command failed with exit code ${code}: ${command} ${args.join(' ')}`))
    })
  })
}

async function syncProjectToTemp() {
  await rm(tempRoot, { recursive: true, force: true })
  await mkdir(tempProjectRoot, { recursive: true })

  await cp(projectRoot, tempProjectRoot, {
    recursive: true,
    force: true,
    filter(source) {
      const normalized = path.resolve(source)
      if (normalized === path.join(projectRoot, 'dist')) {
        return false
      }
      if (normalized === path.join(projectRoot, '.tmp')) {
        return false
      }
      return true
    },
  })
}

async function copyDistBack() {
  await rm(path.join(projectRoot, 'dist'), { recursive: true, force: true })
  await cp(path.join(tempProjectRoot, 'dist'), path.join(projectRoot, 'dist'), {
    recursive: true,
    force: true,
  })
}

async function buildInPlace(root) {
  const targetViteBin = path.join(root, 'node_modules', 'vite', 'bin', 'vite.js')
  await run(nodeBin, [targetViteBin, 'build'], root)
}

async function main() {
  await run(nodeBin, [vueTscBin, '-b'], projectRoot)

  if (isAsciiOnly(projectRoot)) {
    await buildInPlace(projectRoot)
    return
  }

  console.log('[build] Detected non-ASCII project path, using temporary ASCII workspace for Vite build.')

  await syncProjectToTemp()

  try {
    await buildInPlace(tempProjectRoot)
    await copyDistBack()
  } finally {
    await rm(tempRoot, { recursive: true, force: true })
  }
}

main().catch((error) => {
  console.error(error instanceof Error ? error.message : error)
  process.exit(1)
})
