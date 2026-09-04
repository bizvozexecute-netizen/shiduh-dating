const fs = require('fs');
const src = fs.readFileSync(process.argv[2], 'utf8');
const m = src.match(/\/\* MDLITE-START \*\/([\s\S]*?)\/\* MDLITE-END \*\//);
if (!m) { console.error('нет блока MDLITE в chat.js'); process.exit(2); }
const mdlite = new Function(m[1] + '; return mdlite;')();
const inputs = JSON.parse(Buffer.from(process.argv[3], 'base64').toString('utf8'));
console.log(JSON.stringify(inputs.map(t => mdlite.toHtml(t))));
