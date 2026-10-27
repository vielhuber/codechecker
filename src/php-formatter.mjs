import { realpathSync } from 'node:fs';
import { createRequire } from 'node:module';

let [binary, file] = process.argv.slice(2);
let require = createRequire(realpathSync(binary));
let prettier = require('prettier');
let config = await prettier.resolveConfig(file);
let info = await prettier.getFileInfo(file, { resolveConfig: true, plugins: config?.plugins ?? [] });
process.stdout.write(info.inferredParser ?? '');
