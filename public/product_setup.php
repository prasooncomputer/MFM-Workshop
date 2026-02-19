<?php include 'config.php'; ?>
<!doctype html><html><head><title>Product Setup</title><link rel="stylesheet" href="style.css"></head><body>
<?php include 'nav.php'; ?>
<div class="container">
<div class="card">
<h2>Screen 1 — Product Setup</h2>
<p>Create a product first, then define its BOM and process attributes in one save action.</p>
<div class="grid grid-2">
  <input id="p_name" placeholder="Product Name">
  <input id="p_sku" placeholder="SKU (optional)">
</div>
<h4>BOM Attributes</h4>
<table><thead><tr><th>Material</th><th>Qty/Unit</th><th>Unit</th><th></th></tr></thead><tbody id="bom_rows"></tbody></table>
<button onclick="addBomRow()" type="button">+ Add BOM Row</button>

<h4>Process Attributes</h4>
<table><thead><tr><th>Process</th><th>Seq</th><th>Depends on Seq</th><th>Std Time (min)</th><th>Output Rate</th><th>Parallel</th><th></th></tr></thead><tbody id="proc_rows"></tbody></table>
<button onclick="addProcRow()" type="button">+ Add Process Row</button>

<div style="margin-top:10px"><button onclick="saveProductSetup()">Save Product with BOM + Processes</button></div>
</div>

<div class="card">
<h4>Existing Products</h4>
<ul id="products"></ul>
</div>
</div>
<script>
const api='<?= $apiBase ?>';
function bomRow(){return `<tr><td><input class='mat' placeholder='Material'></td><td><input class='qty' type='number' step='0.01' value='0'></td><td><input class='unit' value='pcs'></td><td><button onclick='this.closest("tr").remove()' type='button'>x</button></td></tr>`;}
function procRow(){return `<tr><td><input class='pname' placeholder='Process'></td><td><input class='seq' type='number' value='1'></td><td><input class='depseq' type='number' placeholder='optional'></td><td><input class='stm' type='number' step='0.01' value='1'></td><td><input class='sor' type='number' step='0.01' value='1'></td><td><input class='par' type='checkbox'></td><td><button onclick='this.closest("tr").remove()' type='button'>x</button></td></tr>`;}
function addBomRow(){bom_rows.insertAdjacentHTML('beforeend', bomRow());}
function addProcRow(){proc_rows.insertAdjacentHTML('beforeend', procRow());}
function gather(){
  const bom=[...document.querySelectorAll('#bom_rows tr')].map(r=>({material_name:r.querySelector('.mat').value,qty_per_unit:+r.querySelector('.qty').value,unit:r.querySelector('.unit').value})).filter(x=>x.material_name);
  const processes=[...document.querySelectorAll('#proc_rows tr')].map(r=>({process_name:r.querySelector('.pname').value,sequence_no:+r.querySelector('.seq').value,depends_on_sequence_no:r.querySelector('.depseq').value?+r.querySelector('.depseq').value:null,standard_time_minutes:+r.querySelector('.stm').value,standard_output_rate:+r.querySelector('.sor').value,can_parallel:r.querySelector('.par').checked})).filter(x=>x.process_name);
  return {name:p_name.value,sku:p_sku.value,bom,processes};
}
async function saveProductSetup(){
  const body=gather();
  const r=await fetch(`${api}?action=product_setup`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
  const data=await r.json();
  alert(r.ok?`Saved product #${data.id}`:(data.error||'Failed'));
  if(r.ok){p_name.value='';p_sku.value='';bom_rows.innerHTML='';proc_rows.innerHTML='';addBomRow();addProcRow();loadProducts();}
}
async function loadProducts(){
  const r=await fetch(`${api}?action=products`); const data=await r.json();
  products.innerHTML=(data||[]).map(p=>`<li>#${p.id} ${p.name} (${p.sku||'no sku'})</li>`).join('') || '<li>No products yet</li>';
}
addBomRow();addProcRow();loadProducts();
</script></body></html>
