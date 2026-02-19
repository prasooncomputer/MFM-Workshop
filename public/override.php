<?php include 'config.php'; ?>
<!doctype html><html><head><title>Manager Override</title><link rel="stylesheet" href="style.css"></head><body>
<?php include 'nav.php'; ?>
<div class="container"><div class="card"><h2>Screen 5 — Manager Override</h2>
<select id="task"></select><input id="workers" type="number" placeholder="Workers Assigned"><input id="force_start" type="datetime-local"><input id="force_deadline" type="datetime-local"><select id="priority"><option value="">No change</option><option>low</option><option>normal</option><option>high</option><option>urgent</option></select>
<label><input id="pause" type="checkbox"> Pause Task</label>
<label><input id="lock" type="checkbox"> Lock from Rescheduling</label>
<button onclick="save()">Apply Override</button></div></div>
<script>
const api='<?= $apiBase ?>';
async function load(){const data=await (await fetch(`${api}?action=tasks_today`)).json();task.innerHTML=data.map(t=>`<option value='${t.id}'>#${t.id} ${t.process_name}</option>`).join('');}
async function save(){
 const body={workers_assigned:workers.value?+workers.value:null,force_start:force_start.value?new Date(force_start.value).toISOString().slice(0,19).replace('T',' '):null,force_deadline:force_deadline.value?new Date(force_deadline.value).toISOString().slice(0,19).replace('T',' '):null,pause_flag:pause.checked?1:0,manual_lock:lock.checked?1:0,priority_override:priority.value||null};
 const r=await fetch(`${api}?action=override&task_id=${task.value}`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
 alert(r.ok?'Override saved':'Failed');
}
load();
</script></body></html>
