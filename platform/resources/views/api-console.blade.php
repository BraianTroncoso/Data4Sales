<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<title>Consola de API — Data4Sales</title>
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=outfit:400,500,600,700|roboto:400,500&display=swap" rel="stylesheet">
{{--
    Autocontenida a propósito: sin CDN, sin build, sin dependencias.
    Es una herramienta de evaluación, y no tiene sentido que arrastre
    un megabyte de terceros al repositorio de la entrega.
--}}
<style>
:root{
/* Paleta de marca Data4Sales escrita a mano: esta vista no pasa por Vite ni por Tailwind.
   Tema OSCURO del producto (violeta sobre negro), muestreado del CSS de producción. */
--bg:#121212;--surface:#181818;--panel:#181818;--panel2:#2d2d2d;--code:#0e0e0e;
--line:#2f3237;--line-strong:#40444b;
/* #f2f2f2 es el "blanco" de marca; el blanco puro sólo va sobre violeta. */
--txt:#f2f2f2;--dim:#8b9197;--faint:#6b7178;
--accent:#7340ff;--accent-hover:#8659ff;--accent-active:#2c0f7e;
/* Violeta claro para TEXTO sobre negro: el #7340ff pleno da ~3.3:1 sobre #181818
   y no alcanza para leer; #b9a0ff da ~8.7:1 sobre #0e0e0e. El violeta pleno
   queda para superficies y bordes, nunca para texto. */
--accent-txt:#b9a0ff;
--ok:#06c996;--warn:#fea82f;--err:#ff2e00;--sky:#3a8bdf;
/* Variantes CLARAS para texto: al invertir el tema, los tonos oscurecidos del
   tema claro (#04815f, #8a5a06, #2a6fb0, #d92600) quedaron ilegibles sobre negro.
   Sobre fondo oscuro los plenos sí se leen, y donde no llegaban se aclararon.
   Medidos contra #0e0e0e (el fondo de los bloques de código, el peor caso):
   #2ad6a8 ≈ 9.5:1 · #fea82f ≈ 10:1 · #ff5c38 ≈ 6.3:1 · #5fa5e8 ≈ 7.4:1. */
--ok-txt:#2ad6a8;--warn-txt:#fea82f;--err-txt:#ff5c38;--sky-txt:#5fa5e8;
/* Tintes al ~10% del acento, valores exactos de las capturas del producto. */
--tint-sky:#182430;--tint-ok:#11241f;--tint-warn:#292115;--tint-err:#2a1410;--tint-violet:#1b1235;
/* La elevación NO se comunica con sombra en este sistema: cada tarjeta se define
   por un borde de 1px. El único uso de box-shadow es el halo violeta de hover. */
--halo:0 0 24px -2px rgba(115,64,255,.35);
--ui:"Outfit",system-ui,-apple-system,sans-serif;
--num:"Roboto",system-ui,sans-serif;
--mono:ui-monospace,"SF Mono",Menlo,Consolas,monospace}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--txt);font:14px/1.55 var(--ui);-webkit-font-smoothing:antialiased}
header{padding:1rem 1.25rem;border-bottom:1px solid var(--line);background:var(--surface);
display:flex;gap:1rem;align-items:center;flex-wrap:wrap}
h1{font-size:1rem;margin:0;font-weight:600;letter-spacing:-.01em}
/* El chip va sobre --code y no sobre --panel2: el gris secundario sobre #2d2d2d
   se queda en 4.3:1, y sobre #0e0e0e sube a 6:1. */
.tag{font:11px var(--mono);background:var(--code);border:1px solid var(--line);padding:.15rem .5rem;border-radius:8px;color:var(--dim)}
main{display:grid;grid-template-columns:290px 1fr;min-height:calc(100vh - 57px)}
@media(max-width:900px){main{grid-template-columns:1fr}}
nav{border-right:1px solid var(--line);background:var(--surface);padding:.75rem;overflow-y:auto;max-height:calc(100vh - 57px)}
.grp{font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--dim);margin:1rem .5rem .35rem;font-weight:600}
.ep{display:block;width:100%;text-align:left;background:none;border:0;color:var(--txt);padding:.45rem .5rem;
border-radius:8px;cursor:pointer;font-family:var(--ui);font-size:13px;display:flex;gap:.5rem;align-items:center}
.ep:hover{background:var(--panel2)}
.ep:focus-visible{outline:2px solid var(--accent-hover);outline-offset:-2px}
.ep.on{background:var(--tint-violet);color:var(--accent-txt);font-weight:500;box-shadow:inset 2px 0 0 var(--accent)}
.m{font:10px var(--mono);padding:.1rem .35rem;border-radius:8px;min-width:38px;text-align:center}
/* Badges de método: tono claro sobre tinte oscuro (≈6:1 y ≈8.7:1). */
.m.GET{background:var(--tint-sky);color:var(--sky-txt)}.m.POST{background:var(--tint-ok);color:var(--ok-txt)}
section{padding:1.25rem;overflow-y:auto;max-height:calc(100vh - 57px)}
.card{background:var(--panel);border:1px solid var(--line);border-radius:20px;padding:1.25rem;margin-bottom:1rem}
.path{font:13px var(--mono);color:var(--accent-txt);word-break:break-all}
.desc{color:var(--dim);font-size:13px;margin:.6rem 0 0;white-space:pre-line}
label{display:block;font-size:11px;color:var(--dim);margin:.7rem 0 .2rem;text-transform:uppercase;letter-spacing:.05em;font-weight:600}
input,select,textarea{width:100%;background:var(--code);border:1px solid var(--line);color:var(--txt);
padding:.45rem .6rem;border-radius:10px;font:13px var(--mono)}
input:focus,select:focus,textarea:focus{outline:0;border-color:var(--accent);box-shadow:0 0 0 3px rgba(115,64,255,.35)}
/* Los placeholders llevan pistas reales (batch_id, fechas), así que van con el
   gris secundario y no con --faint, que sobre #0e0e0e se queda en 3.9:1. */
input::placeholder{color:var(--dim)}
/* Cuerpo del request: bloque de código, mismo tratamiento que la respuesta. */
textarea{min-height:190px;resize:vertical;line-height:1.45}
.row{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.6rem}
/* Acción principal: píldora violeta con blanco puro (≈5.4:1), el único lugar
   donde se usa #ffffff. */
button.go{background:var(--accent);color:#ffffff;border:0;padding:.75rem 1.75rem;border-radius:999px;
font-family:var(--ui);font-weight:600;cursor:pointer;margin-top:1rem;font-size:13px;min-height:44px;
transition:background-color .15s ease,box-shadow .15s ease}
button.go:hover{background:var(--accent-hover);box-shadow:var(--halo)}
button.go:active{background:var(--accent-active);box-shadow:none}
/* El CSS de producción de la marca no define foco en ningún botón: este anillo
   es decisión nuestra y se conserva. */
button.go:focus-visible{outline:2px solid var(--accent-hover);outline-offset:3px}
button.go:disabled{opacity:.5;cursor:wait;box-shadow:none}
.meta{display:flex;gap:1rem;align-items:center;font:12px var(--num);margin-bottom:.6rem;flex-wrap:wrap}
/* Status HTTP: 2xx verde claro, 4xx ámbar, 5xx rojo aclarado. El #ff2e00 pleno
   da 4.7:1 sobre #181818 y se rompe en cuanto cae sobre el tinte; #ff5c38 no. */
.s2{color:var(--ok-txt);font-weight:500}.s4{color:var(--warn-txt);font-weight:500}.s5{color:var(--err-txt);font-weight:500}
pre{background:var(--code);border:1px solid var(--line);border-radius:10px;padding:.85rem;overflow:auto;
max-height:60vh;font:12px/1.5 var(--mono);color:var(--txt);margin:0}
/* Resaltado de JSON: cuatro matices distinguibles y todos ≥6:1 sobre #0e0e0e.
   Clave violeta claro, string verde, número ámbar, booleano/null azul de dato. */
.k{color:var(--accent-txt)}.v-s{color:var(--ok-txt)}.v-n{color:var(--warn-txt)}.v-b{color:var(--sky-txt)}
.hint{color:var(--dim);font-size:12px;margin-top:.4rem}
.hint code{background:var(--code);border:1px solid var(--line);padding:.05rem .3rem;border-radius:8px;font-size:11px}
</style>
</head>
<body>

<header>
  <h1>Consola de API</h1>
  <span class="tag">Data4Sales</span>
  <span class="tag" id="who"></span>
  <div style="margin-left:auto;display:flex;gap:.5rem;align-items:center">
    <label style="margin:0;text-transform:none;font-size:12px">Tenant</label>
    <select id="tenant" style="width:auto"></select>
  </div>
</header>

<main>
  <nav id="nav"></nav>
  <section>
    <div class="card">
      <div class="path"><span id="curM"></span> <span id="curP"></span></div>
      <p class="desc" id="curD"></p>
      <div id="params"></div>
      <div id="bodyWrap" style="display:none">
        <label>Cuerpo del request
          <select id="preset" style="width:auto;display:inline-block;margin-left:.5rem;text-transform:none"></select>
        </label>
        <textarea id="body" spellcheck="false"></textarea>
      </div>
      <button class="go" id="send">Enviar</button>
      <p class="hint" id="hint"></p>
    </div>
    <div class="card">
      <div class="meta" id="meta"><span style="color:var(--dim)">Sin respuesta todavía.</span></div>
      <pre id="out">—</pre>
    </div>
  </section>
</main>

<script>
const TENANTS = @json($tenants);

/* Cada endpoint declara sus parámetros. Es la misma información que el
   spec en docs/openapi.yaml, en la forma mínima que esta página necesita. */
const EP = [
 {g:'Ingesta',m:'POST',p:'/api/v1/ingest/orders',
  d:'El único punto de entrada de datos. Responde 202, no 201: el lote quedó aceptado para procesar, pero todavía no está reflejado en las tablas de negocio.\nProbá los tres cuerpos de ejemplo para ver cómo trata la suciedad.',
  body:true},
 {g:'Ingesta',m:'GET',p:'/api/v1/ingest/batches/{batch}',
  d:'Estado y cuentas del lote. Un lote de otro tenant es indistinguible de uno inexistente.',
  params:[{n:'batch',ph:'pegá el batch_id que devolvió la ingesta',path:true}]},
 {g:'Ingesta',m:'GET',p:'/api/v1/ingest/batches/{batch}/rejects',
  d:'Qué se rechazó y por qué, agrupado por motivo tipado. Convierte "300 registros fallaron" en algo accionable.',
  params:[{n:'batch',ph:'batch_id',path:true}]},

 {g:'Clientes',m:'GET',p:'/api/v1/customers',
  d:'El email viene ENMASCARADO: un listado se pagina, se exporta y se copia.\nLa búsqueda ignora acentos: probá "gonzalez" y "González" — dan lo mismo.',
  params:[{n:'q',ph:'gonzalez'},{n:'segment',opt:['','champions','loyal','potential_loyalist','new_customers','at_risk','hibernating','lost']},
          {n:'email_status',opt:['','valid','invalid','missing']},{n:'sort',opt:['','last_seen_at','name','external_id']},
          {n:'direction',opt:['','desc','asc']},{n:'per_page',v:'10'}]},
 {g:'Clientes',m:'GET',p:'/api/v1/customers/{customer}',
  d:'Acá SÍ viaja el contacto completo: es la pantalla donde un agente atiende un caso puntual.',
  params:[{n:'customer',ph:'uuid del cliente',path:true}]},

 {g:'Órdenes',m:'GET',p:'/api/v1/orders',
  d:'Los filtros de fecha van contra el día calendario EN LA ZONA DEL TENANT, precalculado.',
  params:[{n:'from',ph:'2026-06-01'},{n:'to',ph:'2026-06-30'},{n:'status',ph:'cancelled,returned'},
          {n:'net_only',opt:['','1']},{n:'late_arrivals',opt:['','1']},{n:'per_page',v:'5'}]},
 {g:'Órdenes',m:'GET',p:'/api/v1/orders/{order}',
  d:'Incluye el historial de estados, con las actualizaciones ignoradas por llegar desordenadas.',
  params:[{n:'order',ph:'uuid de la orden',path:true}]},

 {g:'Métricas',m:'GET',p:'/api/v1/metrics/sales-series',
  d:'Evolución de revenue y órdenes. Lee daily_sales_facts, nunca la tabla de órdenes.',
  params:[{n:'from',ph:'2026-05-01'},{n:'to',ph:'2026-08-31'},{n:'granularity',opt:['day','week','month'],v:'month'}]},
 {g:'Métricas',m:'GET',p:'/api/v1/metrics/segments',
  d:'RFM con quintiles DENTRO de cada tenant. Un campeón de una tienda de barrio no es comparable con uno de una cadena.'},
 {g:'Métricas',m:'GET',p:'/api/v1/metrics/top-products',
  d:'Desde daily_product_facts, con la misma tasa de cambio congelada que usó cada orden.',
  params:[{n:'by',opt:['product','category'],v:'category'},{n:'limit',v:'5'},{n:'from'},{n:'to'}]},
 {g:'Métricas',m:'GET',p:'/api/v1/metrics/retention',
  d:'Recompra. Se calcula sobre customer_metrics, que tiene una fila por cliente: una cohorte mes a mes no respondería en el tenant grande.'},
];

const PRESETS = {
 'Orden limpia': {source:'daily_stream',records:[{order_id:'CONSOLA-'+Date.now(),status:'paid',currency:'ARS',
   total:'12345.67',placed_at:'2026-08-01T10:00:00-03:00',updated_at:'2026-08-01T10:00:00-03:00',
   customer:{customer_id:'CUS-CONSOLA',name:'María González',email:'maria@example.test',country:'AR'},
   items:[{product_id:'P-1',sku:'SKU-1',name:'Remera',category:'indumentaria',quantity:2,unit_price:'1000.00'}]}]},
 'Registros sucios': {source:'daily_stream',records:[
   {order_id:'SUCIA-'+Date.now(),status:'complete',currency:'ARS',total:'1.234,56',
    placed_at:'2026-08-01 22:30:00',customer:{customer_id:'CUS-S1',email:'no es un mail'},items:[]},
   {order_id:'AJENA-'+Date.now(),tenant_id:'00000000-0000-4000-8000-000000000000',status:'paid',
    currency:'ARS',total:'100.00',placed_at:'2026-08-01T10:00:00-03:00',
    customer:{customer_id:'CUS-S2',email:'x@example.test'},items:[]},
   {order_id:'MALA-'+Date.now(),status:'en_veremos',currency:'XYZ',total:'-500',
    placed_at:'cuando fue',customer:{customer_id:'CUS-S3',email:'y@example.test'},items:[]}]},
 'Envío cortado a la mitad': {source:'historical_backfill',expected_count:3,records:[
   {order_id:'CORTE-A-'+Date.now(),status:'paid',currency:'ARS',total:'500.00',
    placed_at:'2026-08-01T10:00:00-03:00',customer:{customer_id:'CUS-C1',email:'a@example.test'},items:[]},
   {order_id:'CORTE-B-'+Date.now(),status:'paid',currency:'ARS',total:'600.00',
    placed_at:'2026-08-01T11:00:00-03:00',customer:{customer_id:'CUS-C2',email:'b@example.test'},items:[]}]},
};

let cur = EP[0];
const $ = id => document.getElementById(id);

function pintarTenants(){
  $('tenant').innerHTML = TENANTS.map(t=>`<option value="${t.slug}">${t.name} (${t.slug})</option>`).join('');
  actualizarWho();
}
function tenantActual(){ return TENANTS.find(t=>t.slug===$('tenant').value) || TENANTS[0]; }
function actualizarWho(){
  const t = tenantActual();
  $('who').textContent = `${t.currency} · ${t.timezone} · ${t.strategy}`;
}

function pintarNav(){
  let html='', g=null;
  EP.forEach((e,i)=>{
    if(e.g!==g){ g=e.g; html+=`<div class="grp">${g}</div>`; }
    html+=`<button class="ep" data-i="${i}"><span class="m ${e.m}">${e.m}</span><span>${e.p.replace('/api/v1','')}</span></button>`;
  });
  $('nav').innerHTML=html;
  $('nav').querySelectorAll('.ep').forEach(b=>b.onclick=()=>elegir(+b.dataset.i));
}

function elegir(i){
  cur = EP[i];
  $('nav').querySelectorAll('.ep').forEach((b,j)=>b.classList.toggle('on',j===i));
  $('curM').textContent = cur.m;
  $('curP').textContent = cur.p;
  $('curD').textContent = cur.d || '';
  $('params').innerHTML = (cur.params||[]).length
    ? '<div class="row">'+cur.params.map(p=>{
        const campo = p.opt
          ? `<select data-p="${p.n}">${p.opt.map(o=>`<option ${o===(p.v||'')?'selected':''}>${o}</option>`).join('')}</select>`
          : `<input data-p="${p.n}" value="${p.v||''}" placeholder="${p.ph||''}">`;
        return `<div><label>${p.n}${p.path?' (en la ruta)':''}</label>${campo}</div>`;
      }).join('')+'</div>'
    : '';
  $('bodyWrap').style.display = cur.body ? 'block' : 'none';
  if(cur.body && !$('preset').options.length){
    $('preset').innerHTML = Object.keys(PRESETS).map(k=>`<option>${k}</option>`).join('');
    $('preset').onchange = cargarPreset;
  }
  if(cur.body) cargarPreset();
  $('hint').textContent = cur.m==='POST'
    ? 'Se envía con Idempotency-Key: reenviar lo mismo devuelve el lote original, no crea uno nuevo.'
    : '';
}

function cargarPreset(){
  const nombre = $('preset').value || Object.keys(PRESETS)[0];
  // Se regenera para que cada envío tenga identificadores nuevos: si no, el
  // segundo intento sería un duplicado exacto y no se vería la ingesta.
  const p = JSON.parse(JSON.stringify(PRESETS[nombre]).replace(/\d{13}/g, Date.now()));
  $('body').value = JSON.stringify(p,null,2);
}

function colorear(o,ind){
  const sp=' '.repeat(ind);
  if(o===null) return '<span class="v-b">null</span>';
  if(typeof o==='number') return `<span class="v-n">${o}</span>`;
  if(typeof o==='boolean') return `<span class="v-b">${o}</span>`;
  if(typeof o==='string') return `<span class="v-s">"${o.replace(/</g,'&lt;')}"</span>`;
  if(Array.isArray(o)) return o.length? `[\n${o.map(v=>sp+'  '+colorear(v,ind+2)).join(',\n')}\n${sp}]` : '[]';
  const ks=Object.keys(o);
  return ks.length? `{\n${ks.map(k=>`${sp}  <span class="k">"${k}"</span>: ${colorear(o[k],ind+2)}`).join(',\n')}\n${sp}}` : '{}';
}

async function enviar(){
  const t = tenantActual();
  let url = cur.p;
  const qs = new URLSearchParams();
  (cur.params||[]).forEach(p=>{
    const el = $('params').querySelector(`[data-p="${p.n}"]`);
    const v = el ? el.value.trim() : '';
    if(!v) return;
    if(p.path) url = url.replace(`{${p.n}}`, encodeURIComponent(v));
    else qs.set(p.n, v);
  });
  if([...qs].length) url += '?'+qs;

  const opts = { method:cur.m, headers:{ 'Authorization':'Bearer '+t.apiKey, 'Accept':'application/json' } };
  if(cur.body){
    opts.headers['Content-Type']='application/json';
    opts.headers['Idempotency-Key']='consola-'+Date.now();
    opts.body = $('body').value;
  }

  $('send').disabled = true;
  const t0 = performance.now();
  try{
    const r = await fetch(url, opts);
    const ms = (performance.now()-t0).toFixed(0);
    const txt = await r.text();
    let cuerpo; try{ cuerpo = JSON.parse(txt); }catch{ cuerpo = txt; }
    const cls = r.status<300?'s2':r.status<500?'s4':'s5';
    $('meta').innerHTML =
      `<span class="${cls}">HTTP ${r.status}</span><span style="color:var(--dim)">${ms} ms</span>`+
      `<span style="color:var(--dim)">${cur.m} ${url}</span>`+
      `<span style="color:var(--dim)">tenant: ${t.slug}</span>`;
    $('out').innerHTML = typeof cuerpo==='string' ? cuerpo.slice(0,4000) : colorear(cuerpo,0);
  }catch(e){
    $('meta').innerHTML = `<span class="s5">Error de red</span>`;
    $('out').textContent = e.message;
  }
  $('send').disabled = false;
}

pintarTenants(); pintarNav(); elegir(0);
$('send').onclick = enviar;
$('tenant').onchange = actualizarWho;
</script>
</body>
</html>
