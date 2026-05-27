<?php
$storageDir = __DIR__ . '/storage';
$completedLog = $storageDir . '/completed_tasks.txt';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0777, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'log_completion') {
    header('Content-Type: application/json');
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON payload']);
        exit;
    }

    $line = sprintf(
        "%s | %s | due:%s | source:%s\n",
        date('c'),
        trim((string)($payload['task'] ?? 'Unknown task')),
        trim((string)($payload['due'] ?? 'N/A')),
        trim((string)($payload['source'] ?? 'task_list'))
    );

    $written = file_put_contents($completedLog, $line, FILE_APPEND | LOCK_EX);
    if ($written === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not write completion log']);
        exit;
    }

    echo json_encode(['ok' => true]);
    exit;
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Adaptive Daily Flow Dashboard</title>

  <style>
    :root {
      --panel: #111827; --panel-2: #1f2937; --text: #e5e7eb; --muted: #9ca3af; --primary: #60a5fa;
      --success: #34d399; --warning: #f59e0b; --danger: #f87171; --border: #334155;
    }
    * { box-sizing: border-box; }
    body { margin:0; padding:18px; background:linear-gradient(180deg,#020617 0%,#0f172a 100%); color:var(--text); font-family:Inter,system-ui,sans-serif; }
    .container { max-width:1360px; margin:0 auto; display:grid; grid-template-columns: minmax(380px,1fr) minmax(420px,1.1fr); gap:14px; }
    .panel { background:rgba(17,24,39,.96); border:1px solid var(--border); border-radius:14px; padding:14px; }
    h1,h2,h3 { margin:0 0 8px; } p,.hint{ color:var(--muted); margin:0 0 10px; }
    .row { display:grid; grid-template-columns: 1.7fr .85fr .85fr auto; gap:8px; margin-bottom:8px; }
    input, textarea, select { width:100%; border-radius:10px; border:1px solid var(--border); background:var(--panel-2); color:var(--text); padding:10px; }
    textarea { min-height:100px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    button { border:none; border-radius:10px; padding:10px 12px; background:var(--primary); color:#031126; font-weight:700; cursor:pointer; }
    button.secondary { background:transparent; border:1px solid var(--border); color:var(--text); }
    .btns { display:flex; gap:8px; flex-wrap:wrap; margin:8px 0; }
    .badge { display:inline-block; margin-left:8px; border-radius:999px; border:1px solid var(--border); font-size:.74rem; padding:2px 8px; }
    .critical{ color:var(--danger); border-color:rgba(248,113,113,.7);} .high{ color:#fb923c; border-color:rgba(251,146,60,.7);} .normal{ color:var(--success); border-color:rgba(52,211,153,.6);} .today{ color:var(--warning); border-color:rgba(245,158,11,.8);} 
    .compact-header{display:flex;justify-content:space-between;align-items:center;gap:8px;}
    .kpi{font-size:.82rem;color:var(--muted);}
    ul { list-style:none; padding:0; margin:0; max-height:430px; overflow:auto; display:flex; flex-direction:column; gap:8px; }
    li.card { background:#0b1220; border:1px solid var(--border); border-radius:10px; padding:10px; display:grid; grid-template-columns:1fr auto; gap:10px; align-items:center; }
    .title{ font-weight:700; } .meta{ color:var(--muted); font-size:.88rem; } .done{opacity:.6;text-decoration:line-through;}
    .dropzone { border:1px dashed var(--primary); background:rgba(96,165,250,.08); border-radius:10px; padding:10px; color:var(--muted); text-align:center; margin-bottom:8px; }
    .dropzone.active { background:rgba(96,165,250,.18); color:var(--text); }
    .schedule { max-height:410px; overflow:auto; display:flex; flex-direction:column; gap:8px; }
    .slot { border:1px solid var(--border); background:#09101d; border-radius:10px; padding:10px; }
    .status { font-size:.85rem; color:var(--muted); }
    @media (max-width: 1180px){ .container{grid-template-columns:1fr;} }
    @media (pointer: coarse){ button,input,select,textarea{min-height:44px;} .btns button{flex:1 1 180px;} }
  </style>
</head>
<body>
  <div class="container">
    <section class="panel">
      <h1>Adaptive Daily Flow Dashboard</h1>
      <p>Create prioritized tasks, export a planner JSON + easy-copy prompt, import an LLM adaptive plan, and check things off as you complete them.</p>
      <div class="compact-header"><h2>Add Task</h2><div id="taskKpi" class="kpi"></div></div>
      <div class="row">
        <input id="taskTitle" type="text" placeholder="Task title">
        <input id="taskDue" type="date">
        <select id="taskPriority">
          <option value="critical">Critical</option>
          <option value="high">High</option>
          <option value="normal" selected>Normal</option>
        </select>
        <button id="addTaskBtn">Add</button>
      </div>
      <div class="btns">
        <button class="secondary" id="sortBtn">Sort</button>
        <button class="secondary" id="exportBtn">Export JSON</button>
        <button class="secondary" id="copyPromptBtn">Copy LLM Prompt</button>
        <button class="secondary" id="clearDoneBtn">Remove Completed</button>
      </div>
      <ul id="taskList"></ul>
      <p class="hint" id="logStatus">Completion log: storage/completed_tasks.txt</p>
    </section>

    <section class="panel">
      <h2>LLM Exchange</h2>
      <h3>Tasks JSON</h3>
      <textarea id="exportArea" readonly></textarea>
      <h3>Copy/Paste Prompt (separate from JSON)</h3>
      <textarea id="promptArea" readonly></textarea>
      <h3>Import Adaptive LLM Plan JSON</h3>
      <div id="dropZone" class="dropzone">Drag & drop LLM plan JSON here, or paste below.</div>
      <textarea id="importArea" placeholder='{"version":"1.0","day_plan":[{"id":"p1","time":"09:00-09:30","task":"...","linked_task":"Task title","status":"pending","replan_if_late":"move to next free slot","replan_if_early":"pull next critical task"}]}'></textarea>
      <div class="btns"><button id="importBtn">Import Plan</button></div>
      <h3>Guided Schedule (9:00–4:00)</h3>
      <div id="scheduleOutput" class="schedule"><div class="status">No plan imported.</div></div>
    </section>
  </div>

<script>
const STORAGE_TASKS='daily_flow_tasks_v2';
const STORAGE_PLAN='daily_flow_plan_v2';
const taskTitle=document.getElementById('taskTitle');
const taskDue=document.getElementById('taskDue');
const taskPriority=document.getElementById('taskPriority');
const taskList=document.getElementById('taskList');
const exportArea=document.getElementById('exportArea');
const promptArea=document.getElementById('promptArea');
const importArea=document.getElementById('importArea');
const scheduleOutput=document.getElementById('scheduleOutput');
const logStatus=document.getElementById('logStatus');
const taskKpi=document.getElementById('taskKpi');
const dropZone=document.getElementById('dropZone');

let tasks=JSON.parse(localStorage.getItem(STORAGE_TASKS)||'[]');
let plan=JSON.parse(localStorage.getItem(STORAGE_PLAN)||'[]');

const llmPrompt=`You are scheduling my day from 09:00 to 16:00.\nUse the JSON tasks I provide.\nReturn strict JSON with:\n{\n  "version": "1.0",\n  "strategy": "...",\n  "day_plan": [\n    {\n      "id": "slot-id",\n      "time": "HH:MM-HH:MM",\n      "task": "what I should do",\n      "linked_task": "matching dashboard task title",\n      "status": "pending",\n      "energy": "high|medium|low",\n      "replan_if_late": "exact fallback if I am behind",\n      "replan_if_early": "exact action if I finish early",\n      "must_do": true/false,\n      "notes": "short coaching note"\n    }\n  ]\n}\nRules: prioritize due dates and critical tasks, include short breaks, and ensure the plan can adapt when behind or ahead.`;

function saveTasks(){localStorage.setItem(STORAGE_TASKS,JSON.stringify(tasks));}
function savePlan(){localStorage.setItem(STORAGE_PLAN,JSON.stringify(plan));}
function daysFromNow(d){const t=new Date();t.setHours(0,0,0,0);const due=new Date(d+'T00:00:00');return Math.floor((due-t)/(1000*60*60*24));}

function sortTasks(){
  const prio={critical:0,high:1,normal:2};
  tasks.sort((a,b)=>{const d=new Date(a.due)-new Date(b.due); if(d!==0) return d; return prio[a.priority]-prio[b.priority];});
  saveTasks(); renderTasks();
}

function urgencyBadge(task){
  const diff=daysFromNow(task.due);
  const dueTag=diff<0?'Overdue':(diff===0?'Due today':'Due in '+diff+' day(s)');
  const dueClass=diff===0?'today':(task.priority||'normal');
  return `<span class="badge ${task.priority||'normal'}">${(task.priority||'normal').toUpperCase()}</span><span class="badge ${dueClass}">${dueTag}</span>`;
}

async function logCompletion(task, source='task_list'){
  try{
    const res=await fetch('?action=log_completion',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({task:task.title,due:task.due,source})});
    const out=await res.json();
    if(out.ok){ logStatus.textContent='Completion logged to storage/completed_tasks.txt'; }
  } catch(e){ logStatus.textContent='Could not write completion log in this environment.'; }
}

function updateKpi(){
  const pending=tasks.filter(t=>!t.done).length;
  const criticalPending=tasks.filter(t=>!t.done && t.priority==='critical').length;
  taskKpi.textContent=`Pending: ${pending} · Critical pending: ${criticalPending}`;
}

function renderTasks(){
  taskList.innerHTML='';
  if(!tasks.length){taskList.innerHTML='<li class="card"><div class="meta">No tasks yet.</div></li>'; return;}
  tasks.forEach((task,idx)=>{
    const li=document.createElement('li'); li.className='card';
    li.innerHTML=`<div><div class="title ${task.done?'done':''}">${task.title}${urgencyBadge(task)}</div><div class="meta">Due: ${task.due} · ${task.done?'Completed':'Pending'}</div></div>
    <div class="btns"><button class="secondary" data-action="toggle" data-index="${idx}">${task.done?'Undo':'Done'}</button><button class="secondary" data-action="delete" data-index="${idx}">Delete</button></div>`;
    taskList.appendChild(li);
  });
  updateKpi();
}


function exportTasks(){
  const payload={exported_at:new Date().toISOString(),workday_window:'09:00-16:00',tasks:tasks.map(t=>({title:t.title,due:t.due,priority:t.priority,done:t.done})),llm_schema:'adaptive_plan_v1',requirements:['must include replan_if_late and replan_if_early per slot','must include linked_task and must_do']};
  exportArea.value=JSON.stringify(payload,null,2);
  promptArea.value=llmPrompt;
}

function renderPlan(){
  scheduleOutput.innerHTML='';
  if(!plan.length){scheduleOutput.innerHTML='<div class="status">No plan imported.</div>'; return;}
  plan.forEach((slot,i)=>{
    const div=document.createElement('div'); div.className='slot';
    const must=slot.must_do?' <span class="badge critical">MUST DO</span>':'';
    div.innerHTML=`<strong>${slot.time||'TBD'}</strong>${must}<div>${slot.task||'Task missing'}</div><div class="meta">Linked task: ${slot.linked_task||'N/A'} · Energy: ${slot.energy||'N/A'}</div><div class="meta">If late: ${slot.replan_if_late||'N/A'}</div><div class="meta">If early: ${slot.replan_if_early||'N/A'}</div><div class="btns"><button class="secondary" data-plan-done="${i}">Mark slot done</button></div>`;
    scheduleOutput.appendChild(div);
  });
  updateKpi();
}


document.getElementById('addTaskBtn').addEventListener('click',()=>{
  const title=taskTitle.value.trim(), due=taskDue.value, priority=taskPriority.value;
  if(!title||!due){alert('Task title and due date are required.');return;}
  tasks.push({title,due,priority,done:false}); sortTasks(); taskTitle.value=''; exportTasks();
});

document.getElementById('sortBtn').addEventListener('click',sortTasks);
document.getElementById('exportBtn').addEventListener('click',exportTasks);
document.getElementById('copyPromptBtn').addEventListener('click',async()=>{try{await navigator.clipboard.writeText(promptArea.value);alert('Prompt copied.');}catch{alert('Clipboard unavailable. You can copy manually from the prompt box.');}});
document.getElementById('clearDoneBtn').addEventListener('click',()=>{tasks=tasks.filter(t=>!t.done);saveTasks();renderTasks();exportTasks();});

taskList.addEventListener('click',async e=>{
  const btn=e.target.closest('button'); if(!btn) return;
  const idx=Number(btn.dataset.index); const task=tasks[idx];
  if(btn.dataset.action==='toggle'){tasks[idx].done=!tasks[idx].done; if(tasks[idx].done){await logCompletion(tasks[idx]);}}
  if(btn.dataset.action==='delete'){tasks.splice(idx,1);} saveTasks(); renderTasks(); exportTasks();
});


function applyImportedPlanText(text){
  const parsed=JSON.parse(text);
  const incoming=Array.isArray(parsed)?parsed:parsed.day_plan;
  if(!Array.isArray(incoming)) throw new Error('Use array or {"day_plan": [...]}');
  plan=incoming; savePlan(); renderPlan();
}

dropZone.addEventListener('dragover',e=>{e.preventDefault(); dropZone.classList.add('active');});
dropZone.addEventListener('dragleave',()=>dropZone.classList.remove('active'));
dropZone.addEventListener('drop',e=>{
  e.preventDefault(); dropZone.classList.remove('active');
  const file=e.dataTransfer.files && e.dataTransfer.files[0];
  if(!file){return;}
  const reader=new FileReader();
  reader.onload=()=>{importArea.value=String(reader.result||''); try{applyImportedPlanText(importArea.value);}catch(err){alert('Invalid JSON: '+err.message);}};
  reader.readAsText(file);
});

document.getElementById('importBtn').addEventListener('click',()=>{
  try{applyImportedPlanText(importArea.value);}
  catch(err){alert('Invalid JSON: '+err.message);} 
});

scheduleOutput.addEventListener('click',async e=>{
  const btn=e.target.closest('button[data-plan-done]'); if(!btn) return;
  const idx=Number(btn.dataset.planDone); const slot=plan[idx];
  if(!slot) return;
  slot.status='done';
  const linked=(slot.linked_task||'').toLowerCase();
  const match=tasks.find(t=>t.title.toLowerCase()===linked);
  if(match){match.done=true; await logCompletion(match,'plan_slot'); saveTasks(); renderTasks();}
  savePlan(); renderPlan(); exportTasks();
});

sortTasks(); exportTasks(); renderPlan();
</script>
</body>
</html>
