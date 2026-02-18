<?php include 'config.php'; ?>
<!doctype html><html><head><title>Production Planning</title><link rel="stylesheet" href="style.css"></head><body>
<?php include 'nav.php'; ?>
<div class="container">
<div class="card"><h2>Screen 2 — Production Planning</h2>
<select id="product"></select><input id="qty" type="number" placeholder="Quantity"><input id="start" type="datetime-local"><select id="priority"><option>normal</option><option>high</option><option>urgent</option><option>low</option></select>
<button onclick="preview()">Preview Timeline</button><button onclick="releaseOrder()">Release to Production</button>
</div>
<div class="card"><h3>Material Requirement</h3><div id="materials"></div></div>
<div class="card"><h3>Gantt Timeline</h3><table><thead><tr><th>Process</th><th>Start</th><th>End</th><th>Duration (min)</th></tr></thead><tbody id="timeline"></tbody></table></div>
</div>
<script>
const api='<?= $apiBase ?>';
async function loadProducts(){const ps=await (await fetch(api+'/api/products')).json();product.innerHTML=ps.map(p=>`<option value='${p.id}'>${p.name}</option>`).join('');}
async function preview(){
 const body={product_id:+product.value,quantity:+qty.value,start_date:new Date(start.value).toISOString(),priority:priority.value};
 const data=await (await fetch(api+'/api/planning/preview',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)})).json();
 materials.innerHTML=data.materials.map(m=>`<p>${m.material_name}: ${m.required_qty} ${m.unit}</p>`).join('');
 timeline.innerHTML=data.timeline.map(t=>`<tr><td>${t.process_name}</td><td>${t.planned_start}</td><td>${t.planned_end}</td><td>${t.duration_min}</td></tr>`).join('');
}
async function releaseOrder(){
 const body={product_id:+product.value,quantity:+qty.value,start_date:new Date(start.value).toISOString().slice(0,19).replace('T',' '),priority:priority.value};
 const r=await fetch(api+'/api/orders/release',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
 alert(r.ok?'Order released':'Failed to release');
}
loadProducts();
</script></body></html>
