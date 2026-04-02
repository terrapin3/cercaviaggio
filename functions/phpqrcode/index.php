<?php    

	require("qrlib.php");
 
//Directory del nostro file
 //$DIR = './temp/';
 
// ECC Level, livello di correzione dell'errore (valori possibili in ordine crescente: L,M,Q,H - da low a high)
$errorCorrectionLevel = 'L';
 
// Grandezza della matrice dimensioni variabili da 1 a 10 
$matrixPointSize = 4;
 
// I dati da codificare nel nostro QRcode
$data = "http://codematrix.altervista.org";
 
// Salviamo il file
$filename = 'qrcode'.md5($data.'|'.$errorCorrectionLevel.'|'.$matrixPointSize).'.png';
 
// Creamo il nostro QRcode in formato PNG
QRcode::png($data, $filename, $errorCorrectionLevel, $matrixPointSize, 2);
 
// Stampo immagine a video
//echo '<img src="'.$DIR.basename($filename).'" /><hr/>';

?>