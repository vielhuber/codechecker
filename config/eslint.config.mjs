import javascript from '@eslint/js';
import globals from 'globals';
import typescript from 'typescript-eslint';

export default [
    { files: ['**/*.{js,mjs,cjs,jsx,ts,mts,cts,tsx}'] },
    javascript.configs.recommended,
    ...typescript.configs.recommended,
    {
        languageOptions: {
            globals: { ...globals.browser, ...globals.node },
            parserOptions: { ecmaFeatures: { jsx: true } }
        }
    }
];
