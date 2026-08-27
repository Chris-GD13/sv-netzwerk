from pathlib import Path

root = Path(__file__).resolve().parents[1]
core = root / 'public/intern/api/gf-ai-generate-core.php'
source = core.read_text(encoding='utf-8')
old = "function gfOpenAIUploadName(string $name,string $mime):string{$mime=mb_strtolower(trim($mime),'UTF-8');$extensions=['image/jpeg'=>'jpg','image/jpg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];$extension=$extensions[$mime]??'';if($extension==='')return$name;$base=pathinfo($name,PATHINFO_FILENAME);if(trim($base)==='')$base='Schadenbild';return$base.'.'.$extension;}"
new = "function gfOpenAIUploadName(string $name,string $mime):string{$mime=mb_strtolower(trim($mime),'UTF-8');$extensions=['image/jpeg'=>'jpg','image/jpg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','application/pdf'=>'pdf','application/msword'=>'doc','application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx','application/vnd.ms-excel'=>'xls','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx','text/plain'=>'txt','text/csv'=>'csv'];$base=pathinfo($name,PATHINFO_FILENAME);if(trim($base)==='')$base='Unterlage';$extension=$extensions[$mime]??mb_strtolower((string)pathinfo($name,PATHINFO_EXTENSION),'UTF-8');if($extension==='')return$name;return$base.'.'.$extension;}"
if old not in source:
    raise SystemExit('gfOpenAIUploadName anchor not found')
source = source.replace(old, new, 1)
core.write_text(source, encoding='utf-8')
print('GF OpenAI upload filenames normalized to lowercase supported extensions.')
