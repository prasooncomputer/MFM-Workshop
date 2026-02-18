<?php include 'config.php'; ?>
<!doctype html><html><head><title>Update Task</title><link rel="stylesheet" href="style.css"></head><body>
<?php include 'nav.php'; ?>
<div class="container"><div class="card"><h2>Screen 4 — Update Task (Mobile Friendly)</h2>
<select id="task"></select><input id="employee" placeholder="Employee Name"><input id="output" type="number" placeholder="Output Qty"><input id="reject" type="number" placeholder="Reject Qty"><input id="hours" type="number" step="0.1" placeholder="Hours"><button onclick="submitLog()">Save Log</button>
</div></div>
<script>
const api='<?= $apiBase ?>';
async function load(){const data=await (await fetch(api+'/api/tasks/today')).json();task.innerHTML=data.map(t=>`<option value='${t.id}'>#${t.id} ${t.process_name}</option>`).join('');}
async function submitLog(){
 const body={task_id:+task.value,employee_name:employee.value,output_qty:+output.value,reject_qty:+reject.value,hours_worked:+hours.value};
 const r=await fetch(api+'/api/logs',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
 alert(r.ok?'Logged':'Failed');
}
load();
</script></body></html>
