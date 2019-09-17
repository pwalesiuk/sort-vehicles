<?php

  $out_dir = __DIR__ . '/out/';
  $in_dir = __DIR__ . '/in/';

  require __DIR__ . '/vendor/autoload.php';

  use Monolog\Logger;
  use Monolog\Handler\StreamHandler;

  $log_level = Logger::DEBUG;

  function sort_veh($a, $b) {
    global $wzorce;
    $fna = $a->getAttribute('filename');
    $fnb = $b->getAttribute('filename');
    $fia = (int) $a->getAttribute('id');
    $fib = (int) $b->getAttribute('id');
    foreach ($wzorce as $klucz => $wzor) {
      if (preg_match('/' . $wzor . '/i', $fna)) {
        if (preg_match('/' . $wzor . '/i', $fnb)) {
          // obydwa pasują do tego samego wzorca
          break;
        } else {
          return -1;
        }
      } elseif (preg_match('/' . $wzor . '/i', $fnb)) {
        return 1;
      }
    }
    return $fia - $fib;
  }

  function pat_line_is_ok($fline) {
    if (strlen(trim($fline)) < 3 || $fline[0] == '#' || $fline[0] == ';') return false;
    return true;
  }

  function daj_file_name(&$item1, $key)
  {
    $item1 = basename($item1, '.xml');
  }

  $options = getopt('f:');
  if (!isset($options['f'])) {
    die("use: $argv[0] -f source_file" . PHP_EOL);
  }
  libxml_use_internal_errors(true);
  
  $logger = new Logger('gen');
  $stream = new StreamHandler($out_dir . 'xsort.log', $log_level);
  $logger->pushHandler($stream);

  $logger->addInfo('start', $argv);
  if (!is_file($options['f'])) {
    $logger->addError('file not found', array($options['f']));
    die('file not found: ' . $options['f']);
  }

  $all_name = array();

  $logger->addInfo('loading xml file', array($options['f']));
  $dom = new DOMDocument();
  if (!$dom->load($options['f'])) {
    echo "Failed loading XML" .PHP_EOL;
    $ter = array();
    foreach(libxml_get_errors() as $error) {
      echo "\t", $error->message, PHP_EOL;
      $ter[] = $error->message;
    }
    $logger->addError("failed loading xml", $ter);
    exit(1);
  }

  $afile = $out_dir . 'org.xml';
  $logger->addDebug('saving org file', array('org.xml'));
  file_put_contents($afile, $dom->saveXML());

  $wzorce = array();
  $pfile = $in_dir . 'wzorce.txt';
  $logger->addDebug('wczytuję wzorce', array($pfile));
  if (!is_file($pfile)) {
    $logger->addError('brak pliku z wzorcami', array($pfile));
    die('brak pliku z wzorcami: ' . $pfile);
  }
  $wzorce = array_filter(file($pfile), 'pat_line_is_ok');
  $wzorce = array_map('rtrim', $wzorce);
  if (count($wzorce) == 0) {
    $logger->addError('brak wzorców w pliku', array($pfile));
    die('brak wzorców w pliku: ' . $pfile);
  }
  $logger->addInfo('wczytane wzorce', $wzorce);

  $xp = new DOMXPath($dom);
  $m = iterator_to_array($xp->query('vehicle/@filename'));
  foreach ($m as $elem) {
    $sn = (string) $elem->value;
    if ($sn) {
      $all_name[] = $sn;
    }
  }
  sort($all_name);
  $logger->addDebug('saving all names', array('all_name.txt'));
  file_put_contents($out_dir . 'all_name.txt', implode(PHP_EOL, $all_name));

  $uniq_names = $all_name;
  array_walk($uniq_names, 'daj_file_name');
  $uniq_names = array_unique($uniq_names);
  sort($uniq_names);
  $logger->addDebug('saving pojazdy', array('pojazdy.txt'));
  file_put_contents($out_dir . 'pojazdy.txt', implode(PHP_EOL, $uniq_names));
  $nowe = array();
  foreach($uniq_names as $nowy) {
    if (!in_array($nowy, $wzorce)) $nowe[] = $nowy;
  }

  $xp = new DOMXPath($dom);
  $xveh = $xp->query('/vehicles/vehicle');
  $vehicles = iterator_to_array($xveh);

  usort($vehicles, 'sort_veh');

  $newdoc = new DOMDocument('1.0', 'UTF-8');
  $libraries = $newdoc->appendChild($newdoc->importNode($dom->documentElement));
  $ind = 0;
  $zmiany = array();
  foreach ($vehicles as $veh) {
    $org_id = $veh->getAttribute('id');
    // na razie nie zmieniamy numeracji
    $ind = $org_id;
    $veh->setAttribute('id', $ind);
    $zmiany[] = sprintf('%s [%d] => %d', $veh->getAttribute('filename'), $org_id, $veh->getAttribute('id'));
    $libraries->appendChild($newdoc->importNode($veh, true));
  }
  $logger->addDebug('saving zmiany', array('zmiany.txt'));
  file_put_contents($out_dir . 'zmiany.txt', implode(PHP_EOL, $zmiany));

  $atms = $dom->getElementsByTagName("attachments");
  foreach ($atms as $atm) {
    $libraries->appendChild($newdoc->importNode($atm, true));
  }

  $afile = $out_dir . 'new.xml';
  $logger->addDebug('saving new file', array('new.xml'));
  file_put_contents($afile, $newdoc->saveXML());

  if (count($nowe) > 0) {
    $logger->addDebug('nowe wzorce', array('nowe.txt'));
    file_put_contents($out_dir . 'nowe.txt', implode(PHP_EOL, $nowe));
  }

  $logger->addInfo('stop');
  
?>
