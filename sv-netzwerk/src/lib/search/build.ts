import { getCollection } from 'astro:content';
import { sortedLibrary, slugify } from '../../data/library';
import { damageTypes } from '../../data/damage-types';
import { experts } from '../../data/experts';
import { regionalAreas } from '../../data/regions';
import { damageSearchContent } from '../../data/damage-search-content';
import { filterPublishedKnowledgeItems } from '../../utils/publication';
import { normalizeSearchText, tokenizeSearchText } from './normalize';
import type { SearchContentType, SearchIndexItem } from './types';

type Input = Omit<SearchIndexItem,'id'|'categorySlug'|'tagSlugs'|'searchable'|'tokens'|'featured'> & { id?:string; featured?:boolean };
const prepare=(item:Input,index:number):SearchIndexItem=>{const searchable=normalizeSearchText([item.title,item.description,item.category,item.type,...item.tags].join(' '));return {...item,id:item.id??`search-${index+1}`,featured:Boolean(item.featured),categorySlug:slugify(item.category),tagSlugs:item.tags.map(slugify),searchable,tokens:tokenizeSearchText(searchable)};};

export async function buildSearchIndex(){
  const knowledge=await getCollection('knowledge');
  const publishedKnowledge = filterPublishedKnowledgeItems(knowledge.filter((entry)=>entry.data.publication.status==='published' && Boolean(entry.data.publication.publishedAt)));
  const inputs:Input[]=[
    ...sortedLibrary,
    ...publishedKnowledge.map((entry)=>({id:`knowledge-${entry.id}`,title:entry.data.title,description:entry.data.description,href:`/fachwissen/${entry.id}/`,category:entry.data.category,tags:entry.data.tags,date:entry.data.publication.publishedAt!.toISOString().slice(0,10),type:'article' as SearchContentType,featured:entry.data.featured})),
    ...damageTypes.map((item)=>({id:`damage-${item.id}`,title:damageSearchContent[item.slug]?.h1 ?? item.title,description:damageSearchContent[item.slug]?.intro ?? item.description,href:`/schadenarten/${item.slug}/`,category:'Schadenarten',tags:[item.shortTitle,...item.inspectionTopics],date:'2026-10-14',type:'damage' as SearchContentType})),
    ...regionalAreas.map((area)=>({id:`region-${area.slug}`,title:`Gutachter und Schadenregulierer ${area.city}`,description:area.lead,href:`/regionen/${area.slug}/`,category:'Standorte und Regionen',tags:[area.city,area.region,...damageTypes.filter((item)=>area.damageSlugs.includes(item.slug)).map((item)=>item.title),...experts.filter((expert)=>area.expertSlugs.includes(expert.slug)).flatMap((expert)=>expert.expertise)],date:'2026-10-14',type:'page' as SearchContentType})),
    {id:'page-schadengutachten',title:'Schadengutachten und Versicherungsgutachter',description:'Schadengutachten für Gebäude- und Sachschäden: Ursache, Schadenumfang und Wiederherstellungskosten nachvollziehbar bewerten.',href:'/leistungen/schadengutachten/',category:'Leistungen',tags:['Gutachter','Versicherungsschaden','Schadengutachten'],date:'2026-10-14',type:'page'},
    ...experts.filter((expert)=>expert.status==='active').map((expert)=>({id:`expert-${expert.id}`,title:expert.name,description:expert.shortProfile,href:`/experten/${expert.slug}/`,category:'Experten',tags:[expert.role,...expert.expertise,...expert.regions],date:'2026-07-14',type:'expert' as SearchContentType})),
    {id:'page-svos',title:'SV Operating System',description:'Daten-, Fachartikel- und Prozessstruktur des SV-Netzwerks.',href:'/svos/',category:'Plattform',tags:['SVOS','Prozess'],date:'2026-07-14',type:'page'},
    {id:'page-network',title:'Netzwerk – Organisation und Konzept',description:'Arbeitsweise, Qualitätsstruktur und Zusammenarbeit im SV-Netzwerk.',href:'/netzwerk/',category:'Unternehmen',tags:['Netzwerk','Zusammenarbeit'],date:'2026-07-14',type:'page'},
  ];
  const unique=new Map<string,Input>();for(const item of inputs)unique.set(item.href,item);
  return [...unique.values()].map(prepare).sort((a,b)=>b.date.localeCompare(a.date));
}
