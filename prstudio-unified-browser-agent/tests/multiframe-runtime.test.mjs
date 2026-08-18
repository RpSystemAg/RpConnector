import test from 'node:test';
import assert from 'node:assert/strict';

function eventApi() {
  const listeners = new Set();
  return { addListener(fn){listeners.add(fn)}, removeListener(fn){listeners.delete(fn)}, async emit(...args){for(const fn of [...listeners]) await fn(...args)} };
}
function storageArea(){const m=new Map();return {async get(k){if(k==null)return Object.fromEntries(m);const o={};for(const x of(Array.isArray(k)?k:typeof k==='string'?[k]:Object.keys(k||{})))if(m.has(x))o[x]=m.get(x);return o},async set(v){for(const[k,x]of Object.entries(v||{}))m.set(k,x)},async remove(k){for(const x of(Array.isArray(k)?k:[k]))m.delete(x)}}}
const local=storageArea(), session=storageArea(); const noop=async()=>undefined;
globalThis.chrome={
 runtime:{onInstalled:eventApi(),onStartup:eventApi(),onMessage:eventApi(),onConnect:eventApi(),getURL:p=>`chrome-extension://test/${p}`},
 alarms:{onAlarm:eventApi(),create:noop,clear:async()=>true,get:async()=>null},
 tabs:{onCreated:eventApi(),onReplaced:eventApi(),onRemoved:eventApi(),onActivated:eventApi(),onUpdated:eventApi(),query:async()=>[],get:async id=>({id,windowId:1,url:'https://main.test/',title:'Main'}),update:noop,create:async()=>({id:1,windowId:1,url:'about:blank'}),remove:noop,captureVisibleTab:async()=>'',sendMessage:async()=>({ok:true,result:{pong:true}})},
 windows:{onRemoved:eventApi(),onFocusChanged:eventApi(),getAll:async()=>[],get:async()=>({id:1,tabs:[]}),create:async()=>({id:1,tabs:[]}),update:noop,remove:noop,WINDOW_ID_NONE:-1},
 debugger:{onDetach:eventApi(),onEvent:eventApi(),getTargets:async()=>[],attach:noop,detach:noop,sendCommand:async()=>({})},
 storage:{local,session},action:{setBadgeText:noop,setBadgeBackgroundColor:noop},scripting:{executeScript:async()=>[]},notifications:{create:noop}
};
const {__test}=await import(`../service-worker.js?multiframe=${Date.now()}`);

function portFor(tabId,frameId,url,resolver){
 const onMessage=eventApi(), onDisconnect=eventApi();
 return {name:'prstudio-page-runtime',sender:{tab:{id:tabId,url:'https://main.test/'},frameId,url,documentId:`doc-${frameId}`},onMessage,onDisconnect,disconnect(){},postMessage(msg){
   if(msg?.type!=='runtime_request') return;
   Promise.resolve(resolver(msg.payload)).then(result=>onMessage.emit({type:'runtime_response',id:msg.id,ok:true,result}));
 }};
}
const describe=(name,frameId,inDialog=false)=>({targetRef:`r${frameId}`,role:'button',accessibleName:name,text:name,label:'',context:inDialog?'dialog':'',clickable:true,focusable:true,disabled:false,ariaHidden:false,pointerEventsNone:false,inViewport:true,occluded:false,inDialog,centerDistance:.1,selector:`#b${frameId}`,boundingBox:{x:10,y:10,width:100,height:30}});

test('persistent runtime aggregates all frames and ranks targets globally', async()=>{
 const tabId=91;
 const defs=new Map([
  [0,{url:'https://main.test/',name:'Wrong'}],
  [2,{url:'https://child.test/',name:'Save'}],
  [4,{url:'https://grand.test/',name:'Grand Action'}],
 ]);
 for(const [frameId,d] of defs){
  const port=portFor(tabId,frameId,d.url,payload=>{
    if(payload.kind==='dom_action'&&payload.action==='page_snapshot') return {ok:true,url:d.url,title:`f${frameId}`,text:`text-${frameId}`,runtime:{domVersion:frameId+1},interactionMap:{generation:1},interactive:[describe(d.name,frameId,frameId===2)]};
    if(payload.kind==='dom_action'&&payload.action==='locate'){
      if(frameId===2)return {ok:true,matched:true,element:describe('Save',frameId,true),match:{strategy:'semantic_rank',score:420}};
      if(frameId===0)return {ok:true,matched:true,element:describe('Save',frameId,false),match:{strategy:'semantic_rank',score:390}};
      return {ok:false,error:'element_not_found'};
    }
    return {pong:true};
  });
  await chrome.runtime.onConnect.emit(port);
  await port.onMessage.emit({type:'runtime_ready',domVersion:frameId+1,url:d.url});
 }
 assert.equal(__test.runtimeFramesForTab(tabId).size,3);
 const snap=await __test.snapshotAcrossRuntimeFrames(tabId,{includeInteractive:true,maxChars:10000},1000);
 assert.equal(snap.runtime.frameCount,3);
 assert.deepEqual(new Set(snap.interactive.map(x=>x.frameId)),new Set([0,2,4]));
 const loc=await __test.locateAcrossRuntimeFrames(tabId,'locate',{role:'button',name:'Save',intendedAction:'click'},1000);
 assert.equal(loc.element.frameId,2);
 assert.equal(loc.element.inDialog,true);
});

test('recursive OOPIF auto attach targets child debugger sessions directly', async()=>{
 const calls=[]; chrome.debugger.sendCommand=async(target,method,params)=>{calls.push({target,method,params});return {}};
 await __test.armFrameAutoAttach(91,'child-session');
 assert.equal(calls.length,1);
 assert.deepEqual(calls[0].target,{tabId:91,sessionId:'child-session'});
 assert.equal(calls[0].method,'Target.setAutoAttach');
 assert.equal(calls[0].params.flatten,true);
});
