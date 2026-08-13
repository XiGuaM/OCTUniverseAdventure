import fs from "node:fs";
import path from "node:path";
import zlib from "node:zlib";
let b64="";
for(let i=1;;i++){const f=`payload-v2/p${i}.txt`;if(!fs.existsSync(f))break;b64+=fs.readFileSync(f,"utf8").trim()}
const files=JSON.parse(zlib.gunzipSync(Buffer.from(b64,"base64")).toString("utf8"));
for(const [name,content] of Object.entries(files)){fs.mkdirSync(path.dirname(name),{recursive:true});fs.writeFileSync(name,content)}
console.log(`expanded ${Object.keys(files).length} files`);
