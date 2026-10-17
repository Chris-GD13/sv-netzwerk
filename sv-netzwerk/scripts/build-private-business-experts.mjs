import { build } from 'esbuild';
import { readFile, writeFile } from 'node:fs/promises';
const result=await build({entryPoints:['src/data/experts.ts'],bundle:true,write:false,format:'esm',platform:'node'});
const { experts }=await import('data:text/javascript;base64,'+Buffer.from(result.outputFiles[0].text).toString('base64'));
const items=experts.filter(e=>e.status==='active'&&e.group==='expert').map(e=>({id:e.slug,name:e.name})).sort((a,b)=>a.name.localeCompare(b.name,'de'));
if(!items.length||new Set(items.map(e=>e.id)).size!==items.length)throw Error('Expert registry is empty or has duplicate IDs');
const content=JSON.stringify(items,null,2)+'\n',path='public/intern/api/private-business-experts.json';
if(process.argv.includes('--check')){if(await readFile(path,'utf8')!==content)throw Error('Private business expert registry differs from website source');}else await writeFile(path,content);
console.log('Private business expert registry: '+items.length+' active experts from src/data/experts.ts');
