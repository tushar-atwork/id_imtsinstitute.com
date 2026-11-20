<?php
declare(strict_types=1);
final class ListFilters {
 /** Single text value (search boxes, dates). */
 public static function value(string $key):string {return isset($_GET[$key]) && is_string($_GET[$key])?mb_substr(trim($_GET[$key]),0,254):'';}
 /** Multi-select values: accepts key[]=a&key[]=b or a single key=a. Empty means "All". */
 public static function values(string $key):array {
  $raw=$_GET[$key]??[];if(is_string($raw))$raw=[$raw];if(!is_array($raw))return [];
  $out=[];foreach($raw as $v){if(!is_string($v))continue;$v=mb_substr(trim($v),0,254);if($v!=='')$out[$v]=$v;if(count($out)>=200)break;}
  return array_values($out);
 }
 /** "expr IN (?,?,…)" and appends the bound values. */
 public static function in(string $expr,array $values,array &$args):string {array_push($args,...array_values($values));return $expr.' IN ('.implode(',',array_fill(0,count($values),'?')).')';}
 public static function scope(PDO $db,array $user):array {
  if($user['role']==='admin')return ['s.is_active=1',[]];
  if(in_array($user['role'],['document_collection','form_filled','verification'],true))return ['s.is_active=1 AND EXISTS(SELECT 1 FROM crm_team_assignments mine WHERE mine.student_id=s.id AND mine.team_role=? AND mine.assigned_user_id=?)',[$user['role'],$user['id']]];
  return ['s.is_active=1 AND EXISTS(SELECT 1 FROM crm_student_assignments mine WHERE mine.student_id=s.id AND mine.assigned_user_id=?)',[$user['id']]];
 }
 public static function dropdown(PDO $db,array $user,string $key,string $label,string $column):string {
  [$scope,$args]=self::scope($db,$user);$allowed=['university','counsellor_name','session_name','admission_confirmation_status','admission_status'];if(!in_array($column,$allowed,true))throw new LogicException('Invalid filter column');
  $q=$db->prepare("SELECT DISTINCT s.$column FROM crm_students s WHERE $scope AND s.$column IS NOT NULL AND s.$column<>'' ORDER BY s.$column");$q->execute($args);$v=$q->fetchAll(PDO::FETCH_COLUMN);return self::select($key,$label,$v?array_combine($v,$v):[]);
 }
 /** Multi-select filter. Works natively without JavaScript; assets/multi-select.js turns it into a checkbox dropdown. */
 public static function select(string $key,string $label,array $options):string {
  $e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$chosen=self::values($key);
  $html='<label>'.$e($label).'<select name="'.$e($key).'[]" multiple data-multi data-placeholder="All" size="'.max(2,min(6,count($options))).'">';
  foreach($options as $value=>$text)$html.='<option value="'.$e($value).'"'.(in_array((string)$value,$chosen,true)?' selected':'').'>'.$e($text).'</option>';return $html.'</select></label>';
 }
 public static function dates(string $prefix,string $label):string {
  $h='';foreach(['from','to'] as $edge){$key=$prefix.'_'.$edge;$h.='<label>'.htmlspecialchars($label.' '.$edge,ENT_QUOTES,'UTF-8').'<input type="date" name="'.$key.'" value="'.htmlspecialchars(self::value($key),ENT_QUOTES,'UTF-8').'"></label>';}return $h;
 }
 public static function dateWhere(array &$where,array &$args,string $prefix,string $expr):void {
  foreach(['from','to'] as $edge){$v=self::value($prefix.'_'.$edge);if($v==='')continue;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v)continue;
   $where[]=$expr.($edge==='from'?' >= ?':' < ?');$args[]=$edge==='from'?$v:$d->modify('+1 day')->format('Y-m-d');}
 }
 /** Matches any of the selected values. */
 public static function equality(array &$where,array &$args,string $key,string $expr):void {$v=self::values($key);if($v)$where[]=self::in($expr,$v,$args);}
 public static function owners(PDO $db,array $user,string $key,string $role):string {
  [$scope,$args]=self::scope($db,$user);$team=$role!=='legacy';$table=$team?'crm_team_assignments':'crm_student_assignments';
  $q=$db->prepare("SELECT DISTINCT u.id,u.name FROM crm_students s JOIN $table a ON a.student_id=s.id JOIN crm_users u ON u.id=a.assigned_user_id WHERE $scope".($team?' AND a.team_role=?':'').' ORDER BY u.name,u.id');if($team)$args[]=$role;$q->execute($args);$options=['unassigned'=>'Unassigned'];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$options[$r['id']]=$r['name'];return self::select($key,$team?(['document_collection'=>'Documents','form_filled'=>'Form Filled','verification'=>'Verification'][$role].' owner'):'Responsible user',$options);
 }
 /** Any of the selected owners; "unassigned" can be combined with named employees. */
 public static function ownerWhere(array &$where,array &$args,string $key,string $role):void {
  $vals=self::values($key);if(!$vals)return;$team=$role!=='legacy';$table=$team?'crm_team_assignments':'crm_student_assignments';$expr="SELECT 1 FROM $table own WHERE own.student_id=s.id".($team?' AND own.team_role=?':'');
  $ids=array_values(array_filter($vals,'ctype_digit'));$parts=[];
  if(in_array('unassigned',$vals,true)){$parts[]='NOT EXISTS('.$expr.' AND own.assigned_user_id IS NOT NULL)';if($team)$args[]=$role;}
  if($ids){if($team)$args[]=$role;$parts[]='EXISTS('.$expr.' AND '.self::in('own.assigned_user_id',$ids,$args).')';}
  if($parts)$where[]='('.implode(' OR ',$parts).')';
 }
 /** Any of the selected latest team statuses; "none" = no follow-up yet for that team. */
 public static function teamStatusWhere(array &$where,array &$args,string $key,string $role):void {
  $vals=self::values($key);if(!$vals)return;$ids=array_values(array_filter($vals,'ctype_digit'));$parts=[];
  if(in_array('none',$vals,true)){$parts[]='NOT EXISTS(SELECT 1 FROM crm_team_followups tf WHERE tf.student_id=s.id AND tf.team_role=?)';$args[]=$role;}
  if($ids){$args[]=$role;$parts[]=self::in('(SELECT tf.status_id FROM crm_team_followups tf WHERE tf.student_id=s.id AND tf.team_role=? ORDER BY tf.id DESC LIMIT 1)',$ids,$args);}
  if($parts)$where[]='('.implode(' OR ',$parts).')';
 }
 /** True when any of the keys has a value (used to auto-open filter sections). */
 public static function any(array $keys):bool {foreach($keys as $k)if(self::values($k))return true;return false;}
 public static function styles():string {return '<style>
.crm-extra-filters{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px!important;width:100%;margin:12px 0;align-items:start!important}
.crm-extra-filters label{display:flex!important;flex-direction:column!important;gap:6px!important;font-size:12px!important;font-weight:500!important;line-height:1.4!important;height:auto!important;min-height:0!important;margin:0!important;align-self:start!important}
.crm-extra-filters input,.crm-extra-filters select,.filters>input,.filters>select{box-sizing:border-box!important;width:100%;height:40px!important;min-height:40px!important;max-height:40px!important;min-width:0!important;padding:8px 10px!important;border:1px solid #dbe3ef!important;border-radius:8px!important;background:#fff!important;font-size:13px!important;line-height:normal!important;flex:0 0 auto!important;margin:0!important}
.crm-extra-filters select[multiple],.filters select[multiple]{height:auto!important;max-height:none!important;min-height:40px!important}
.crm-filter-section{grid-column:1/-1;width:100%;border:1px solid #e4eaf2;border-radius:10px;background:#f8fafc;padding:12px;margin:10px 0}
.crm-filter-section legend{font-size:13px;font-weight:600;padding:0 6px;color:#294b73}
.crm-filter-section summary{cursor:pointer;font-size:13px;font-weight:600;color:#294b73}
.crm-filter-section .p21-filter-grid{margin-top:12px}.crm-filter-section .p21-filter-grid input{height:40px!important;min-height:40px!important;padding:8px 10px!important}
.ms-field{display:flex;flex-direction:column;gap:6px;font-size:12px;font-weight:500;line-height:1.4;min-width:0}
.ms{position:relative;width:100%;min-width:180px;font-size:13px;font-weight:400}
.ms-toggle{box-sizing:border-box;width:100%;height:40px;padding:8px 30px 8px 10px;border:1px solid #dbe3ef;border-radius:8px;background:#fff;color:#1f2d3d;text-align:left;font:inherit;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;position:relative}
.ms-toggle::after{content:"";position:absolute;right:12px;top:50%;width:7px;height:7px;border-right:2px solid #6b7c93;border-bottom:2px solid #6b7c93;transform:translateY(-70%) rotate(45deg)}
.ms-toggle.has-value{border-color:#4a7bd0;background:#f3f7ff}
.ms-panel{position:absolute;z-index:50;left:0;right:0;top:calc(100% + 4px);min-width:220px;max-height:300px;display:flex;flex-direction:column;background:#fff;border:1px solid #dbe3ef;border-radius:8px;box-shadow:0 10px 24px rgba(20,40,70,.14)}
.ms-panel[hidden]{display:none}
.ms-search{margin:8px!important;width:auto!important;height:34px!important;min-height:34px!important}
.ms-actions{display:flex;justify-content:space-between;padding:4px 10px;border-bottom:1px solid #eef2f7}
.ms-actions button{border:0;background:none;color:#2d5fb5;font-size:12px;cursor:pointer;padding:2px 0}
.ms-list{overflow:auto;padding:4px 0}
.ms-list label{display:flex!important;flex-direction:row!important;align-items:center;gap:8px!important;padding:6px 10px;margin:0!important;font-size:13px!important;cursor:pointer}
.ms-list label:hover{background:#f3f6fb}
.ms-list input{width:16px!important;height:16px!important;min-height:0!important;max-height:none!important;padding:0!important;flex:0 0 auto!important}
.ms-empty{padding:8px 10px;color:#6b7c93}
@media(max-width:1000px){.crm-extra-filters{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:600px){.crm-extra-filters{grid-template-columns:1fr}}
</style>'.self::script();}
 /** Checkbox dropdown for select[multiple][data-multi], inlined so it works even if assets/multi-select.js is missing or cached. */
 private static function script():string {return <<<'JS'
<script>
(function(){'use strict';if(window.crmMultiSelect)return;window.crmMultiSelect=true;var open=null;
function close(w){if(!w)return;w.panel.hidden=true;w.toggle.setAttribute('aria-expanded','false');if(open===w)open=null;}
function summary(s){var p=Array.prototype.filter.call(s.options,function(o){return o.selected;});if(!p.length)return s.getAttribute('data-placeholder')||'All';if(p.length===1)return p[0].text;return p.length+' selected';}
function enhance(select){if(select.dataset.msReady)return;select.dataset.msReady='1';
var wrap=document.createElement('div');wrap.className='ms';var toggle=document.createElement('button');toggle.type='button';toggle.className='ms-toggle';toggle.setAttribute('aria-haspopup','listbox');toggle.setAttribute('aria-expanded','false');
var panel=document.createElement('div');panel.className='ms-panel';panel.hidden=true;var search=null;
if(select.options.length>8){search=document.createElement('input');search.type='search';search.className='ms-search';search.placeholder='Search…';search.setAttribute('aria-label','Search options');panel.appendChild(search);}
var actions=document.createElement('div');actions.className='ms-actions';var all=document.createElement('button');all.type='button';all.textContent='Select shown';var none=document.createElement('button');none.type='button';none.textContent='Clear';actions.appendChild(all);actions.appendChild(none);panel.appendChild(actions);
var list=document.createElement('div');list.className='ms-list';list.setAttribute('role','listbox');list.setAttribute('aria-multiselectable','true');var boxes=[];
Array.prototype.forEach.call(select.options,function(opt){var row=document.createElement('label');var box=document.createElement('input');box.type='checkbox';box.checked=opt.selected;box.addEventListener('change',function(){opt.selected=box.checked;refresh();});row.appendChild(box);row.appendChild(document.createTextNode(opt.text));list.appendChild(row);boxes.push({box:box,row:row,opt:opt,text:opt.text.toLowerCase()});});
if(!boxes.length){var em=document.createElement('div');em.className='ms-empty';em.textContent='No options';list.appendChild(em);}
panel.appendChild(list);
function refresh(){toggle.textContent=summary(select);toggle.classList.toggle('has-value',select.selectedOptions.length>0);toggle.title=Array.prototype.map.call(select.selectedOptions,function(o){return o.text;}).join(', ');}
function setAll(state,visibleOnly){boxes.forEach(function(b){if(visibleOnly&&b.row.hidden)return;b.box.checked=state;b.opt.selected=state;});refresh();}
all.addEventListener('click',function(){setAll(true,true);});none.addEventListener('click',function(){setAll(false,false);});
if(search)search.addEventListener('input',function(){var t=search.value.trim().toLowerCase();boxes.forEach(function(b){b.row.hidden=t!==''&&b.text.indexOf(t)===-1;});});
var widget={panel:panel,toggle:toggle};
toggle.addEventListener('click',function(){if(!panel.hidden){close(widget);return;}close(open);panel.hidden=false;toggle.setAttribute('aria-expanded','true');open=widget;if(search)search.focus();});
wrap.addEventListener('keydown',function(ev){if(ev.key==='Escape'){close(widget);toggle.focus();}});
var host=select.parentNode;if(host.tagName==='LABEL'){var field=document.createElement('div');field.className=(host.className?host.className+' ':'')+'ms-field';var cap=document.createElement('span');while(host.firstChild&&host.firstChild!==select)cap.appendChild(host.firstChild);field.appendChild(cap);host.parentNode.replaceChild(field,host);field.appendChild(select);}
select.parentNode.insertBefore(wrap,select);wrap.appendChild(toggle);wrap.appendChild(panel);wrap.appendChild(select);select.hidden=true;select.style.display='none';widget.wrap=wrap;refresh();}
document.addEventListener('click',function(ev){if(open&&!open.wrap.contains(ev.target))close(open);});
function init(){document.querySelectorAll('select[multiple][data-multi]').forEach(enhance);}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();})();
</script>
JS;}
}
