// Builds mod_aianatomy AMD modules: amd/src/*.js -> amd/build/*.min.js (+ .map), Moodle-style named defines.
const fs = require('fs');
const path = require('path');
const babel = require('@babel/core');
const {minify} = require('terser');
const root = process.argv[2];
const src = path.join(root, 'amd/src');
const out = path.join(root, 'amd/build');
(async() => {
    // Vendored three.js: wrap the minified IIFE bundle as a named AMD module.
    const iife = fs.readFileSync(path.join(__dirname, 'three.iife.min.js'), 'utf8');
    fs.writeFileSync(path.join(out, 'three.min.js'),
        'define("mod_aianatomy/three",[],function(){' + iife + ';return __AA_THREE;});\n');
    for (const file of fs.readdirSync(src)) {
        if (!file.endsWith('.js') || file === 'three.js') {
            continue;
        }
        const name = file.replace(/\.js$/, '');
        const code = fs.readFileSync(path.join(src, file), 'utf8');
        const res = babel.transformSync(code, {
            filename: file, sourceFileName: '../src/' + file, sourceMaps: true, babelrc: false, configFile: false,
            plugins: [['@babel/plugin-transform-modules-amd']],
        });
        const named = res.code.replace(/define\(\[/, 'define("mod_aianatomy/' + name + '", [');
        if (named === res.code) {
            throw new Error('No define() in ' + file);
        }
        const min = await minify(named, {sourceMap: {content: res.map, url: name + '.min.js.map'}});
        fs.writeFileSync(path.join(out, name + '.min.js'), min.code);
        fs.writeFileSync(path.join(out, name + '.min.js.map'), min.map);
        console.log('built', name);
    }
})().catch((e) => { console.error(e.message); process.exit(1); });
