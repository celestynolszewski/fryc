<meta charset="utf-8">
<?php
    /*
    
    FRYZJER 0.1 by lisowy.ziom/ToRepublic
      
    WERSJA PRE-TESTOWA! Zdecydowanie sugeruje odpalanie skryptu nie przez przegladarke, tylko przez konsole. 
    Widac wowczas na bierzaco co sie dzieje, co aktualnie skrypt robi itp. Jesli uruchomisz przez przegladarke, odswiezaj adres zawartosc pliku log.txt w przegladarce co jakis czas na drugiej zakladce zeby wiedziec co jest grane.
    
    Pliki wejsciowe uzytkownika:
    
    in.txt - mail:pass, jedna para na linie, delimiter dwukropek - PLIK WYMAGANY
    
    from.txt - nadawcy emaili ktore nas interesuja (np. service@paypal.com)
    subject.txt - tematy wiadomosci ktore nas interesuja
    keyword.txt - frazy wystepujace w tresci maila ktore nas interesuja, na razie radze nie uzywac - trwa dlugo i przynosi slabe skutki, trzeba to przygotowac pod regular expressions
    
    servers.txt - lista domen i odpowiadajace im mailservery. na razie w takiej formie, pozniej obsluga ssl/tls/notls bedzie przeniesiona jako opcja
    
    Wyjsciowe pliki:
    log.txt - log z dzialania
    mails.zip - archiwum z mailami/indexami wiadomosci
    
    */
    include("external.php");
    
    
    /* OPCJE */
    $tworzzip = false; // dodawanie wyniku dzialania skryptu do archiwum zip
    $usundane = false; // usuwanie danych dodanych do ZIP
    $czesanie = true; // przeszukiwanie maili pod katem kryteriow z plikow
    $getMail = false; // pobieranie maili spelniajacych kryteria, umozliwia przegladanie maili offline, jednak operacja trwa dluzej - zalezy od lacza
    $logWrite = true; // zapisywanie loga z dzialania skryptu
    $logfile = "log.txt"; // nazwa pliku z logami
    $imap_open_timeout = 10; // imap_open timeout - placebo, nie dziala w bibliotece php imap
    $imap_read_timeout = 30; // to samo co wyzej
    
    
    //error_reporting(E_ALL ^ E_NOTICE);
    error_reporting(0);
    
    // inicjacja timera dzialania skryptu 
    $timer_full=microtime(true);
    
    // na shared hostingach i tak proces zostanie ubity po dluzszym czasie wykonywania
    // mozna pokombinowac z auto refresh zeby skrypt sam siebie wywolywal co okreslona ilosc czasu
    set_time_limit(0);

    if(!file_exists("mails")) mkdir("mails");
    
    // jesli wlaczony tryb logowania tworzymy plik log.txt do zapisu
    if($logWrite) {
          $logWrite = fopen($logfile,"w");
    }
    
    // te funkcje to placebo w php, timeout sie nie zmienia - i w tym lezy caly problem szybkosci skryptu
    imap_timeout(IMAP_OPENTIMEOUT,$imap_open_timeout); 
    imap_timeout(IMAP_READTIMEOUT,$imap_read_timeout);
    
    // pliki wejsciowe uzytkownika
    (file_exists("in.txt") && filesize("in.txt")>0 ? $file_accounts = fopen("in.txt","r") : die("Brak pliku mail:pass. Upewnij sie ze plik ma nazwe in.txt, nie jest pusty oraz znajduje sie w katalogu glownym skryptu."));         
    (file_exists("from.txt") && filesize("from.txt")>0 ? $file_from = fopen("from.txt","r") : $file_from = false);
    (file_exists("subject.txt") && filesize("subject.txt")>0 ? $file_subject = fopen("subject.txt","r") : $file_subject = false);
    (file_exists("keyword.txt") && filesize("keyword.txt")>0 ? $file_keyword = fopen("keyword.txt","r") : $file_keyword = false);
     $licznik_linii = 0;
     
    /* petla glowna, dla kazdej linii z pliku mail:pass */
    while(($account_line = fgets($file_accounts)) !== FALSE) {
        $licznik_linii++;
        $timer_acc=microtime(true); // timer wszystkich operacji na jednym koncie
        
        $account = explode(":",$account_line);
        $username = trim($account[0]);
        $password = trim($account[1]);
        $timer=microtime(true);
        
        // sprawdzamy jaki mail serwer dla danego maila trzeba wybrac
        $hostname = getHost($username);
        // domyslnie wchodzimy na INBOX, po zalogowaniu w petli trzeba dodac obsluge wszystkich folderow na koncie nie tylko INBOX
        $hostname.='INBOX';
        
        logwrite('Linia numer: '.$licznik_linii,$logWrite);
        logwrite('Mail: '.$username,$logWrite);
        logwrite('Pass: '.$password,$logWrite);
        
        /* niby imap_open dostaje boosta jesli operuje sie na czystych IP zamiast domenach (brak cache dns po stronie php?), do zrobienia pozniej
        $timer=microtime(true);
        $hostname = gethostbyname($hostname);
        logwrite('Pobieranie IP mailservera ('.$hostname.'): '.(microtime(true)-$timer).' sec',$logWrite);
         */
        logwrite('Proba polaczenia i logowania...',$logWrite); 
        $timer=microtime(true);
        // inicjacja polaczenia imap w trybie tylko do odczytu
        //$inbox = imap_open($hostname,$username,$password,OP_READONLY,1);
        
        // wylaczamy dwie metody autentykacji gssapi i ntlm co znacznie przyspiesza proces logowania dla nietorych mailserverow
        $inbox = imap_open($hostname,$username,$password,OP_READONLY,1,array('DISABLE_AUTHENTICATOR' => array('GSSAPI','NTLM')));
        logwrite('Czas polaczenia z serwerem: '.(microtime(true)-$timer).' sec',$logWrite);
        
        // jesli zalogowano pomyslnie
        if($inbox) {
            // resetowanie zmiennych
            $emails_list = '';
            $emails = array();
            $mails_index = '<meta charset="utf-8">Mail: '.$username.'<br />Pass: '.$password.'<br /><hr>';
            
            if ($czesanie) {
                $timer=microtime(true);
                // czesanie maili po nadawcy
                logwrite('Czesanie FROM...',$logWrite);             
                if($file_from) 
                { 
                     while(($linia = fgets($file_from)) !== FALSE) 
                     {  
                        if(trim($linia)) {
                        logwrite($linia,$logWrite);
                          $s_from = imap_search($inbox, ALL , OP_READONLY ); // zawierajace nadawce
                          if($s_from) $emails = array_merge($emails,$s_from);  
                        }  
                      }
                      rewind($file_from);  
                } 
                // czesanie maili po temacie
                logwrite('Czesanie SUBJECT...',$logWrite);
                if($file_subject) 
                { 
                     while(($linia = fgets($file_subject)) !== FALSE) 
                     {   
                        if(trim($linia)) {
                        logwrite($linia,$logWrite);           
                          $s_subject = imap_search($inbox, ALL , OP_READONLY ); // zawierajace w temacie
                          if($s_subject) $emails = array_merge($emails,$s_subject);
                        }
                      }
                      rewind($file_subject);
                 } 
                // czesanie maili po tresci  
                logwrite('Czesanie BODY...',$logWrite);
                if($file_keyword) 
                { 
                     while(($linia = fgets($file_keyword)) !== FALSE) 
                     {  
                        if(trim($linia)) {
                        logwrite($linia,$logWrite);    
                          $s_body = imap_search($inbox,'BODY "'.trim($linia).'"', SE_FREE, "UTF-8"); // zawierajace keyword w tresci
                          if($s_body) $emails = array_merge($emails,$s_body); 
                        } 
                      }
                      rewind($file_keyword);
                }
                logwrite('Laczny czas przeszukiwania: '.(microtime(true)-$timer).' sec',$logWrite);
                
                // usuwanie zduplikowanych id maili z tablicy
                $emails = array_unique($emails);
                
                // zmiana tablicy na string z id maili do pojedynczego odwolania imap
                foreach($emails as $number)
                {
                    $emails_list .= $number.',';
                
                }
                 
                    // jesli znaleziono maile spelniajace wczesniejsze kryteria wyszukiwania
                    if($emails) {
                
                      // tworzymy katalog w mails/ o nazwie aktualnego emaila
                        if (!file_exists("mails/".$username)) mkdir("mails/".$username);	
                	      $path = 'mails/'.$username.'/';
                        $timer=microtime(true);
                    		// pobranie naglowkow wszystkich maili jako jeden request (wydajnosc+++++++++)
                    		$overview = imap_fetch_overview($inbox,$emails_list,0);
                        logwrite('Pobieranie naglowkow: '.(microtime(true)-$timer).' sec',$logWrite);
                       $timer=microtime(true); 
                        
                        if($getMail) logwrite('Znaleziono '.count($emails).' maili. Pobieranie...',$logWrite);     	
                    	// dla kazdego maila czytamy naglowki
                      foreach($overview as $mail) {	
                        
                            // budujemy plik index.html
                            $index = '<meta charset="utf-8">';
                            $output = '<meta charset="utf-8">';
                           // dane z naglowka maila
                        		$index.= '<div style="background-color:#ffffff;width:100%;color:#000000;margin-bottom:20px">';
                        	//	$index.= 'Status: '.($mail->seen ? 'Przeczytana' : 'Nieprzeczytana').'<br />';
                        		$index.= 'Temat: '.mail::decode_header(imap_utf8($mail->subject)).'<br /> ';
                        		$index.= 'From: '.$mail->from.'<br />';
                        		$index.= 'Data: '.$mail->date.'<br />';
                            if($getMail) $index.= 'MsgNo: <a href="'.$mail->msgno.'.html" target="_blank" >'.$mail->msgno.' (kliknij aby wyœwietliæ wiadomoœæ)</a><br />';
                            $index.= '<hr></div>';
                            
                            // jesli ustawiona opcja pobierania maili, pobieramy jego zawartosc body
                            // jesli wiadomosc zawiera zalaczniki sa one rowniez pobierane w tresci - trzeba bedzie je odpowiednio sparsowac kiedys
                            if($getMail) {
                                   // pobieramy cala wiadomosc        
                                  $message = imap_qprint(imap_body($inbox, $mail->msgno)); 
                      
                              		// tresc maila
                              		$output.= $index;
                              	  $output.= $message;
                              	  // zapis maila do oddzielnego pliku
                              	  $save_mail = fopen($path.$mail->msgno.'.html', 'w');
                              	  fwrite($save_mail, $output);
                              	  fclose($save_mail);
                        	  } // if getmail
                        	  $mails_index.=$index;
                           
                    	 } // foreach mail
                    	 
                    	    // zapisanie pliku index.html dla danego konta
                         $save_index = fopen($path.'index.html', 'w');
                         fwrite($save_index, $mails_index);
                         fclose($save_index); 
                          logwrite('Czas pobierania maili: '.(microtime(true)-$timer).' sec',$logWrite);
                          
                            // wrzucamy wszystko do zipa
                            if($tworzzip) {
                             $timer = microtime(true);
                            // tworzenie archiwum zip z wynikami
                            $archiwum = 'mails.zip';
                            $zip = new Zipper;
                            $zip->open($archiwum, ZIPARCHIVE::CREATE);
                            $zip->addDir("mails/".$username, "mails/".$username);
                            $zip->close();
                            logwrite('Budowanie archiwum ZIP: '.(microtime(true)-$timer).' sec',$logWrite);
                           
                           // usuwanie zawartosci mails jesli opcja ustawiona
                            if($usundane) {
                                $timer = microtime(true);
                                deleteDir('mails/'.$username); 
                                logwrite('Usuwanie utworzonych plików/katalogów: '.(microtime(true)-$timer).' sec',$logWrite);
                            }
                       } // tworzzip  
                    } // if emails     
              } // if czesanie 
           } // if inbox
           logwrite('RAZEM: '.(microtime(true)-$timer_acc).' sec',$logWrite);
           
           // logowanie nie powiodlo sie lub inny blad
           if(!$inbox) {
              logwrite('Niepowodzenie. IMAP Error: '.imap_last_error(),$logWrite);
             // dokladniejsze info o bledach - odkomentuj ponizsza linie 
             var_dump(imap_errors(), imap_alerts());
            }
            
            // zamkniecie polaczenia imap
            imap_close($inbox);
            unset($inbox);
          
    
           
          logwrite('Czas wykonywania skryptu od startu: '.(microtime(true)-$timer_full).' sec',$logWrite);
          logwrite('----------------------------------------------',$logWrite);
    } // while wczytywanie mail:pass z pliku
      

    logwrite('Dzia³anie skryptu zakonczone po '.(microtime(true)-$timer_full).' sec',$logWrite);

     echo '<a href="'.$archiwum.'">Pobierz ZIP z wynikiem pracy skryptu</a><br />';
  
    // zamykamy uchwyty
    if($file_from) fclose($file_from);
    if($file_keyword) fclose($file_keyword);
    if($file_subject) fclose($file_subject);
    if($file_accounts) fclose($file_accounts);
    if($logWrite) fclose($logWrite);
?>