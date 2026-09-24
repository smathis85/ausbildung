'use strict';
const search=document.getElementById('search'), department=document.getElementById('department'),statusFilter=document.getElementById('status');
function filterRows(){let count=0;for(const row of document.querySelectorAll('#participants tbody tr')){const match=(!search.value||row.textContent.toLocaleLowerCase('de').includes(search.value.toLocaleLowerCase('de')))&&(!department.value||row.dataset.department===department.value)&&(!statusFilter.value||row.dataset.status===statusFilter.value);row.hidden=!match;if(match)count++;}document.getElementById('filter-count').textContent=count+' Teilnehmer angezeigt';}
if(search){for(const input of [search,department,statusFilter])input.addEventListener('input',filterRows);filterRows();}
document.getElementById('print')?.addEventListener('click',()=>window.print());
