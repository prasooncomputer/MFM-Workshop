<?php include 'config.php'; ?>
<!doctype html><html><head><title>Product Setup</title><link rel="stylesheet" href="style.css"></head><body>
<?php include 'nav.php'; ?>
<div class="container">
<div class="card">
<h2>Screen 1 — Product Setup</h2>
<div class="grid grid-2">
<div>
<h4>Add Product</h4>
<input id="p_name" placeholder="Product Name"><input id="p_sku" placeholder="SKU"><button onclick="addProduct()">Save Product</button>
</div>
<div>
<h4>Add Process</h4>
<input id="pr_name" placeholder="Process Name"><input id="stm" type="number" placeholder="Std Time (min)"><input id="sor" type="number" placeholder="Output Rate"><label><input id="cp" type="checkbox"> Parallel Allowed</label><button onclick="addProcess()">Save Process</button>
</div>
</div>
</div>
<div class="card">
<h4>Routing & BOM</h4>
<div class="grid grid-2">
<div>
<select id="r_product"></select><select id="r_process"></select><input id="seq" type="number" placeholder="Sequence"><select id="dep"></select><button onclick="addRouting()">Link Routing</button>
</div>
<div>
<select id="b_product"></select><input id="material" placeholder="Material"><input id="qty" type="number" placeholder="Qty per unit"><input id="unit" placeholder="Unit"><button onclick="addBOM()">Add BOM</button>
</div>
</div>
</div>
</div>
<script>
const api='<?= $apiBase ?>';
async function list(){
 const products=await (await fetch(api+'/api/products')).json();
 const processes=await (await fetch(api+'/api/processes')).json();
 const pOpt=products.map(p=>`<option value='${p.id}'>${p.name}</option>`).join('');
 const prOpt=processes.map(p=>`<option value='${p.id}'>${p.name}</option>`).join('');
 ['r_product','b_product'].forEach(id=>document.getElementById(id).innerHTML=pOpt);
 document.getElementById('r_process').innerHTML=prOpt;
 document.getElementById('dep').innerHTML=`<option value=''>None</option>`+prOpt;
}
async function post(url,body){await fetch(api+url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});list();}
function addProduct(){post('/api/products',{name:p_name.value,sku:p_sku.value});}
function addProcess(){post('/api/processes',{name:pr_name.value,standard_time_minutes:+stm.value,standard_output_rate:+sor.value,can_parallel:cp.checked});}
function addRouting(){post('/api/product-process',{product_id:+r_product.value,process_id:+r_process.value,sequence_no:+seq.value,depends_on_process_id:dep.value?+dep.value:null});}
function addBOM(){post('/api/bom',{product_id:+b_product.value,material_name:material.value,qty_per_unit:+qty.value,unit:unit.value});}
list();
</script></body></html>
