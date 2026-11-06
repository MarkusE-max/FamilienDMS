<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/document_suggestions.php';

$user=require_login();$error='';
$notice=match(is_string($_GET['message']??null)?$_GET['message']:''){
 'accepted'=>'Vorschlag übernommen. Metadaten, Kategorie und Ordner wurden nach deiner Bestätigung gespeichert.',
 'dismissed'=>'Vorschlag verworfen.',default=>'',
};
$categories=db()->query('SELECT id,name FROM categories ORDER BY name')->fetchAll();
$folders=db()->query('SELECT id,parent_id,name FROM folders ORDER BY name')->fetchAll();
$categoryIds=array_map(static fn(array $r):int=>(int)$r['id'],$categories);
$folderById=[];
foreach($folders as $f)$folderById[(int)$f['id']]=['id'=>(int)$f['id'],'parent_id'=>$f['parent_id']===null?null:(int)$f['parent_id'],'name'=>(string)$f['name']];
$folderPath=static function(?int $id)use($folderById):string{
 if($id===null||!isset($folderById[$id]))return 'Ohne Ordner';$parts=[];$seen=[];
 while($id!==null&&isset($folderById[$id])&&!isset($seen[$id])){$seen[$id]=true;$parts[]=$folderById[$id]['name'];$id=$folderById[$id]['parent_id'];}
 return implode(' / ',array_reverse($parts));
};
$folderIds=array_keys($folderById);
if($_SERVER['REQUEST_METHOD']==='POST'){
 csrf_check();$action=is_string($_POST['action']??null)?$_POST['action']:'';$id=filter_var($_POST['document_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
 if(!in_array($action,['accept','dismiss'],true)||$id===false){http_response_code(400);$error='Der Vorschlag konnte nicht zugeordnet werden.';}
 else{
  $pdo=db();
  try{
   $pdo->beginTransaction();
   $sql='SELECT d.id FROM documents d JOIN document_text t ON t.document_id=d.id WHERE d.id=? AND d.deleted_at IS NULL AND t.status=?';$params=[(int)$id,'indexed'];
   if($user['role']!=='admin'){$sql.=' AND d.uploaded_by=?';$params[]=(int)$user['id'];}
   $sql.=' FOR UPDATE';$q=$pdo->prepare($sql);$q->execute($params);
   if(!$q->fetch())throw new RuntimeException('Das Dokument ist nicht verfügbar oder gehört nicht zu deinem Bereich.');
   $q=$pdo->prepare('SELECT decision FROM document_suggestion_reviews WHERE document_id=?');$q->execute([(int)$id]);
   if($q->fetchColumn()!==false)throw new RuntimeException('Dieser Vorschlag wurde bereits bearbeitet.');
   if($action==='accept'){
    $sender=trim(is_string($_POST['sender']??null)?$_POST['sender']:'');
    $type=trim(is_string($_POST['document_type']??null)?$_POST['document_type']:'');
    $rawDate=trim(is_string($_POST['document_date']??null)?$_POST['document_date']:'');
    $rawCategory=$_POST['category_id']??'';$rawFolder=$_POST['folder_id']??'';
    $categoryId=$rawCategory===''?null:filter_var($rawCategory,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $folderId=$rawFolder===''?null:filter_var($rawFolder,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(mb_strlen($sender)>160||mb_strlen($type)>80)throw new RuntimeException('Absender oder Dokumentart ist zu lang.');
    if($rawDate!==''){
     $date=DateTimeImmutable::createFromFormat('!Y-m-d',$rawDate);$dateErrors=DateTimeImmutable::getLastErrors();
     if(!$date||$date->format('Y-m-d')!==$rawDate||(is_array($dateErrors)&&($dateErrors['warning_count']>0||$dateErrors['error_count']>0)))throw new RuntimeException('Bitte ein gültiges Datum auswählen.');
    }
    if($categoryId===false||($categoryId!==null&&!in_array((int)$categoryId,$categoryIds,true)))throw new RuntimeException('Bitte eine gültige Kategorie wählen.');
    if($folderId===false||($folderId!==null&&!in_array((int)$folderId,$folderIds,true)))throw new RuntimeException('Bitte einen gültigen Ordner wählen.');
    $q=$pdo->prepare('UPDATE documents SET sender=?,document_date=?,document_type=?,folder_id=? WHERE id=?');
    $q->execute([$sender!==''?$sender:null,$rawDate!==''?$rawDate:null,$type!==''?$type:null,$folderId,(int)$id]);
    if($categoryId!==null){
     $q=$pdo->prepare('SELECT 1 FROM document_categories WHERE document_id=? AND category_id=?');$q->execute([(int)$id,(int)$categoryId]);
     if($q->fetchColumn()===false){$q=$pdo->prepare('INSERT INTO document_categories (document_id,category_id) VALUES (?,?)');$q->execute([(int)$id,(int)$categoryId]);}
    }
   }
   $q=$pdo->prepare('INSERT INTO document_suggestion_reviews (document_id,decision,reviewed_by) VALUES (?,?,?)');
   $q->execute([(int)$id,$action==='accept'?'accepted':'dismissed',(int)$user['id']]);
   $pdo->commit();header('Location: '.app_url('suggestions.php?message='.($action==='accept'?'accepted':'dismissed')));exit;
  }catch(RuntimeException $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
  catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('[FamilienDMS] Suggestion review failed: '.get_class($e));$error='Der Vorschlag konnte nicht gespeichert werden. Bitte Datenbankprotokoll prüfen.';}
 }
}
$sql='SELECT d.id,d.title,d.folder_id,d.sender,d.document_date,d.document_type,d.uploaded_by,LEFT(t.content,80000) AS content FROM documents d JOIN document_text t ON t.document_id=d.id LEFT JOIN document_suggestion_reviews r ON r.document_id=d.id WHERE d.deleted_at IS NULL AND t.status=? AND t.content IS NOT NULL AND r.document_id IS NULL';
$params=['indexed'];if($user['role']!=='admin'){$sql.=' AND d.uploaded_by=?';$params[]=(int)$user['id'];}
$sql.=' ORDER BY d.created_at DESC,d.id DESC LIMIT 50';$q=db()->prepare($sql);$q->execute($params);$documents=$q->fetchAll();
$currentCategories=[];
if($documents){$ids=array_map(static fn(array $r):int=>(int)$r['id'],$documents);$marks=implode(',',array_fill(0,count($ids),'?'));$q=db()->prepare('SELECT document_id,category_id FROM document_categories WHERE document_id IN ('.$marks.') ORDER BY category_id');$q->execute($ids);foreach($q->fetchAll() as $r)$currentCategories[(int)$r['document_id']][]=(int)$r['category_id'];}
$cards='';
foreach($documents as $d){
 $id=(int)$d['id'];$proposal=document_suggestion_extract((string)$d['title'],(string)$d['content'],$categories,$folders);
 $sender=(string)($d['sender']??$proposal['sender']??'');$date=(string)($d['document_date']??$proposal['document_date']??'');$type=(string)($d['document_type']??$proposal['document_type']??'');
 $selectedCategory=$proposal['category_id']??($currentCategories[$id][0]??null);$selectedFolder=$proposal['folder_id']??($d['folder_id']===null?null:(int)$d['folder_id']);
 $categoryOptions='<option value="">Keine Kategorie hinzufügen</option>';
 foreach($categories as $c){$cid=(int)$c['id'];$sel=$selectedCategory===$cid?' selected':'';$categoryOptions.='<option value="'.$cid.'"'.$sel.'>#'.h(ltrim((string)$c['name'],'#')).'</option>';}
 $folderOptions='<option value="">Ohne Ordner</option>';
 foreach($folderById as $f){$sel=$selectedFolder===$f['id']?' selected':'';$folderOptions.='<option value="'.$f['id'].'"'.$sel.'>'.h($folderPath($f['id'])).'</option>';}
 $excerpt=trim((string)preg_replace('/\s+/u',' ',(string)$d['content']));if(mb_strlen($excerpt)>360)$excerpt=mb_substr($excerpt,0,360).'…';if($excerpt==='')$excerpt='Kein Textausschnitt vorhanden.';
 $cards.='<article class="panel suggestion-card"><div class="suggestion-card-heading"><div><p class="eyebrow">Dokument #'.$id.'</p><h2>'.h((string)$d['title']).'</h2></div><a class="preview-link" href="'.h(app_url('preview.php?id='.$id)).'">Ansehen</a></div><p class="suggestion-excerpt">'.h($excerpt).'</p><p class="form-hint">Lokale Texterkennung und Stichwortregeln liefern Vorschläge. Bitte prüfe alles; Änderungen erfolgen erst nach Bestätigung.</p><form method="post" class="suggestion-form"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="accept"><input type="hidden" name="document_id" value="'.$id.'"><label>Absender<input name="sender" maxlength="160" value="'.h($sender).'" placeholder="Nicht erkannt"></label><label>Dokumentdatum<input type="date" name="document_date" value="'.h($date).'"></label><label>Dokumentart<input name="document_type" maxlength="80" value="'.h($type).'" placeholder="Bitte prüfen oder ergänzen"></label><label>Kategorie hinzufügen<select name="category_id">'.$categoryOptions.'</select></label><label class="suggestion-field-wide">Ordner<select name="folder_id">'.$folderOptions.'</select></label><div class="suggestion-actions"><button type="submit">Prüfen und übernehmen</button></div></form><form method="post" class="suggestion-dismiss"><input type="hidden" name="_csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="dismiss"><input type="hidden" name="document_id" value="'.$id.'"><button class="button-secondary" type="submit">Vorschlag verwerfen</button></form></article>';
}
$body='<section class="page-intro"><p class="eyebrow">Prüfung vor Ablage</p><h1>Ablagevorschläge</h1><p>FamilienDMS erkennt mögliche Angaben aus dem OCR-Text. Du kannst sie anpassen und bestätigst selbst, ob Kategorie und Ordner übernommen werden.</p></section>';
if($notice!=='')$body.='<p class="alert-success" role="status">'.h($notice).'</p>';
if($error!=='')$body.='<p class="alert-error" role="alert">'.h($error).'</p>';
$body.=$cards!==''?'<section class="suggestion-list" aria-label="Offene Ablagevorschläge">'.$cards.'</section>':'<section class="panel empty-state"><span class="empty-icon" aria-hidden="true">✓</span><strong>Keine offenen Vorschläge</strong><p>Indexierte Dokumente erscheinen hier, sobald die Texterkennung abgeschlossen ist. Neue Dokumente werden vom geplanten OCR-Auftrag eingelesen.</p></section>';
if(count($documents)===50)$body.='<p class="list-footnote">Es werden höchstens 50 offene Vorschläge auf einmal angezeigt. Bearbeite diese, um weitere zu sehen.</p>';
page('Ablagevorschläge',$body);
