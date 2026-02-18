<?php include 'config.php'; ?>
<!doctype html><html><head><title>Live Task Board</title><link rel="stylesheet" href="style.css"></head><body>
<?php include 'nav.php'; ?>
<div class="container"><div class="card"><h2>Screen 3 — Live Work Board</h2><p>Auto refresh every 30 seconds.</p>
<table><thead><tr><th>Task</th><th>Target</th><th>Done</th><th>Expected</th><th>Status</th><th>Dependency</th></tr></thead><tbody id="rows"></tbody></table></div></div>
<script>
const api='<?= $apiBase ?>';
function badge(s){const t=(s==='in_progress'||s==='ready'||s==='pending')?'on_time':s;return `<span class='badge ${t}'>${s}</span>`;}
async function load(){
 const data=await (await fetch(`${api}?action=tasks_today`)).json();
 rows.innerHTML=data.map(t=>`<tr><td>${t.process_name}</td><td>${t.target_quantity}</td><td>${t.completed_quantity}</td><td>${t.expected_progress}</td><td>${badge(t.status)}</td><td>${t.depends_on_task_id? 'Waiting predecessor':''}</td></tr>`).join('');
}
load(); setInterval(load,30000);
</script></body></html>
