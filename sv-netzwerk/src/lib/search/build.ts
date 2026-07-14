import { getCollection } from 'astro:content';
import { library, slugify } from '../../data/library';
import { damageTypes } from '../../data/damage-types';
import { experts } from '../../data/experts';
import { normalizeSearchText, tokenizeSearchText } from './normalize';
import type { SearchContentType, SearchIndexItem } from './types';

type Input = Omit<SearchIndexItem,'id'|'categorySlug'|'tagSlugs'|'searchable'|'tokens'|'featured'> & { id?:string; featured?:boolean };
const prepare=(item:Input,index:number):SearchIndexItem=>{const searchable=normalizeSearchText([item.title,item.description,item.category,item.type,...item.tags].join(' '));return {...item,id:item.id??`search-${index+1}`,featured:Boolean(item.featured),categorySlug:slugify(item.category),tagSlugs:item.tags.map(slugify),searchable,tokens:tokenizeSearchText(searchable)};};

export async function buildSearchIndex(){
  const [knowledge,downloads,cases]=await Promise.all([getCollection('knowledge'),getCollection('downloads'),getCollection('practiceCases')]);
  const inputs:Input[]=[
    ...library,
    ...knowledge.filter((entry)=>entry.data.publication.status==='published').map((entry)=>({id:`knowledge-${entry.id}`,title:entry.data.title,description:entry.data.description,href:`/fachwissen/${entry.id}/`,category:entry.data.category,tags:entry.data.tags,date:entry.data.publication.publishedAt.toISOString().slice(0,10),type:'article' as SearchContentType,featured:entry.data.featured})),
    ...downloads.filter((entry)=>entry.data.publication.status==='published').map((entry)=>({id:`download-${entry.id}`,title:entry.data.title,description:entry.data.description,href:`/downloads/${entry.id}/`,category:entry.data.category,tags:entry.data.tags,date:entry.data.publication.publishedAt.toISOString().slice(0,10),type:'download' as SearchContentType})),
    ...cases.filter((entry)=>entry.data.publication.status==='published').map((entry)=>({id:`case-${entry.id}`,title:entry.data.title,description:entry.data.description,href:`/praxisfaelle/${entry.id}/`,category:entry.data.lossType,tags:[entry.data.objectType,...entry.data.tags],date:entry.data.publication.publishedAt.toISOString().slice(0,10),type:'case' as SearchContentType})),
    ...damageTypes.map((item)=>({id:`damage-${item.id}`,title:item.title,description:item.description,href:`/schadenarten/${item.slug}/`,category:'Schadenarten',tags:[item.shortTitle,...item.inspectionTopics],date:'2026-07-14',type:'damage' as SearchContentType})),
    ...experts.filter((expert)=>expert.status==='active').map((expert)=>({id:`expert-${expert.id}`,title:expert.name,description:expert.shortProfile,href:`/experten/${expert.slug}/`,category:'Experten',tags:[expert.role,...expert.expertise,...expert.regions],date:'2026-07-14',type:'expert' as SearchContentType})),
    {id:'page-svos',title:'SV Operating System',description:'Daten-, Wissens- und Prozessstruktur des SV-Netzwerks.',href:'/svos/',category:'Plattform',tags:['SVOS','Prozess','Module'],date:'2026-07-14',type:'page'},
    {id:'page-network',title:'Netzwerk – Organisation und Konzept',description:'Arbeitsweise, Qualitätsstruktur und Zusammenarbeit im SV-Netzwerk.',href:'/netzwerk/',category:'Unternehmen',tags:['Netzwerk','Zusammenarbeit'],date:'2026-07-14',type:'page'},
  ];
  const unique=new Map<string,Input>();for(const item of inputs)unique.set(item.href,item);
  return [...unique.values()].map(prepare).sort((a,b)=>b.date.localeCompare(a.date));
}
