<?php
declare(strict_types=1);

function document_suggestion_normalize(string $value): string
{
    $value = mb_strtolower($value, 'UTF-8');
    $value = strtr($value, ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']);
    return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value));
}
function document_suggestion_contains(string $text, string $phrase): bool
{
    $phrase = document_suggestion_normalize($phrase);
    return $phrase !== '' && str_contains(' ' . $text . ' ', ' ' . $phrase . ' ');
}
function document_suggestion_date(string $text): ?string
{
    $pat='(\d{4}-\d{2}-\d{2}|\d{1,2}[.\/]\d{1,2}[.\/]\d{4})'; $values=[];
    if (preg_match('/(?:rechnungsdatum|ausstellungsdatum|dokumentdatum|belegdatum|datum)\s*[:\-]?\s*'.$pat.'/iu',$text,$m)) $values[]=$m[1];
    if (preg_match_all('/'.$pat.'/u',mb_substr($text,0,20000),$m)) $values=array_merge($values,$m[1]);
    foreach (array_unique($values) as $v) {
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/',$v,$m)) [$y,$mo,$d]=[(int)$m[1],(int)$m[2],(int)$m[3]];
        elseif (preg_match('/\A(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})\z/',$v,$m)) [$d,$mo,$y]=[(int)$m[1],(int)$m[2],(int)$m[3]];
        else continue;
        if ($y>=1900 && $y<=(int)date('Y')+2 && checkdate($mo,$d,$y)) return sprintf('%04d-%02d-%02d',$y,$mo,$d);
    }
    return null;
}
function document_suggestion_extract(string $title,string $text,array $categories,array $folders): array
{
    $source=mb_substr($title."\n".$text,0,80000); $normalized=document_suggestion_normalize($source);
    $sender=null;
    if (preg_match('/^\h*(?:absender|aussteller|rechnungssteller|rechnung\s+von|von|lieferant|anbieter|versicherer|krankenkasse|arbeitgeber|finanzamt|praxis)\h*[:\-]\h*([^\r\n]{2,160})/imu',mb_substr($text,0,12000),$m)) {
        $sender=mb_substr(trim(trim((string)preg_replace('/\s{2,}/u',' ',$m[1])," \t,;:-")),0,160);
        if ($sender==='') $sender=null;
    }
    $rules=[
      ['Steuerbescheid',['steuerbescheid','einkommensteuerbescheid'],['finanzamt','einkommensteuer','steuererklaerung','festsetzung']],
      ['Kontoauszug',['kontoauszug'],['iban','saldo','kontostand','buchung']],
      ['Lohnabrechnung',['lohnabrechnung','gehaltsabrechnung'],['brutto','netto','abrechnungsmonat']],
      ['Versicherung',['versicherungsschein','versicherungsnummer','versicherungspolice'],['versicherung','versicherungsnehmer','beitrag']],
      ['Gesundheitsdokument',['arztbrief','befundbericht'],['diagnose','rezept','arzt','praxis']],
      ['Vertrag',['vertragsnummer','vertrag'],['vertragslaufzeit','kuendigung','vertragspartner']],
      ['Energieabrechnung',['jahresabrechnung','verbrauchsabrechnung'],['strom','gas','zaehlerstand','kilowattstunde']],
      ['Rechnung',['rechnungsnummer'],['rechnung','rechnungsbetrag','zahlungsziel','gesamtbetrag']],
      ['Bescheid',[],['bescheid','aktenzeichen','festsetzung']],
    ];
    $scores=[];
    foreach($rules as [$name,$strong,$terms]) {
        $score=0;$isStrong=false;$hits=0;
        foreach($strong as $term) if(document_suggestion_contains($normalized,$term)){$score+=8;$isStrong=true;}
        foreach($terms as $term) if(document_suggestion_contains($normalized,$term)){$score++;$hits++;}
        if($isStrong||$hits>=2)$scores[$name]=$score;
    }
    arsort($scores);$type=null;
    if($scores){$names=array_keys($scores);$vals=array_values($scores);if($vals[0]>($vals[1]??-1))$type=$names[0];}
    $aliasMap=[
      'Steuerbescheid'=>['steuer','steuern','steuerbescheid','finanzamt','finanzen'],
      'Kontoauszug'=>['bank','konto','kontoauszug','finanzen'],
      'Lohnabrechnung'=>['arbeit','gehalt','lohn','lohnabrechnung'],
      'Versicherung'=>['versicherung','versicherungen','police'],
      'Gesundheitsdokument'=>['gesundheit','medizin','arzt','krankenkasse'],
      'Vertrag'=>['vertrag','vertraege'],
      'Energieabrechnung'=>['energie','strom','gas','wasser','nebenkosten'],
      'Rechnung'=>['rechnung','rechnungen'],
      'Bescheid'=>['behoerden','bescheid','amt'],
    ];
    $aliases=array_map('document_suggestion_normalize',$aliasMap[$type]??[]);
    $categoryId=null;$best=0;$tie=false;
    foreach($categories as $category){
        $name=document_suggestion_normalize((string)$category['name']);if($name==='')continue;$score=0;
        if($type!==null&&$name===document_suggestion_normalize($type))$score=100;
        elseif(in_array($name,$aliases,true))$score=80;
        elseif(mb_strlen($name)>=4&&document_suggestion_contains($normalized,$name))$score=45;
        if($score>$best){$best=$score;$categoryId=(int)$category['id'];$tie=false;}elseif($score>0&&$score===$best)$tie=true;
    }
    if($tie)$categoryId=null;
    $folderId=null;$best=0;$tie=false;
    foreach($folders as $folder){
        $name=document_suggestion_normalize((string)$folder['name']);if($name==='')continue;$score=0;
        if($categoryId!==null)foreach($categories as $category)if((int)$category['id']===$categoryId&&$name===document_suggestion_normalize((string)$category['name']))$score=90;
        if(!$score&&$type!==null&&$name===document_suggestion_normalize($type))$score=75;
        if(!$score&&in_array($name,$aliases,true))$score=65;
        if(!$score&&mb_strlen($name)>=4&&document_suggestion_contains($normalized,$name))$score=40;
        if($score>$best){$best=$score;$folderId=(int)$folder['id'];$tie=false;}elseif($score>0&&$score===$best)$tie=true;
    }
    if($tie)$folderId=null;
    return ['sender'=>$sender,'document_date'=>document_suggestion_date($text),'document_type'=>$type,'category_id'=>$categoryId,'folder_id'=>$folderId];
}
