<?php
   // Archivage en ZIP ou ZIP64 avec compression DEFLATE, BZIP2 ou STORE
   // -------------------
   // -- Instanciation --
   // -------------------
   // $obj = new zip($method, $onTheFly, $out, $filename);
   // $method : STORE | DEFLATE | BZIP2
   // $onTheFly : FALSE | TRUE (Si TRUE, l'archive est remplie à chaque appel de addFile(), sinon elle est remplie lors de l'appel de createArchive())
   // $out : STDOUT | FILE
   // $filename : si $out = FILE, $filename doit contenir le chemin complet vers le fichier de sortie
   // Dans le cas d'une sortie sur STDOUT, il peut être préférable de positionner $onTheFly à TRUE pour que le téléchargement commence plus rapidement
   // ------------------------
   // -- Niveau de compression
   // ------------------------
   // Il est possible de configurer un niveau de compression entre 1 (faible compression, plus rapide) et 9 (forte compression, moins rapide) => défaut : 4
   // $obj->compressionLevel = 9;
   // -----------------------
   // -- Ajout de fichiers --
   // ----------------------- 
   // $obj->addFile($file, $filename)
   // $file : Chemin complet vers le fichier à archiver
   // $filename : Chemin complet vers le fichier dans l'archive (facultatif, si non fourni, on utilise le $file)
   // ---------------------------
   // -- Création de l'archive --
   // ---------------------------
   // Cet appel de focntion n'est pas utile si $onTheFly = TRUE, il ne provoquera cependant aucun plantage dans ce cas 
   // createArchive()
   // ----------------------------
   // -- Finalisation de l'archive
   // ----------------------------
   // finalizeArchive()
   
   namespace processid\archive;
   
   use \processid\compress\Bzip2;
   use \processid\compress\Deflate;
   
   class Zip {
      protected $_zip64;
      protected $_offsetLocalFileHeader;
      protected $_sizeCentralDirectory;
      protected $_nbFiles;
      protected $_centralDirectoryString;
      protected $_arrayFiles;
      protected $_method;
      protected $_out;
      protected $_tmpFile;
      protected $_outFilePointer;
      protected $_onTheFly;
      protected $_compressionLevel;
      
      // Constantes de méthode de compression ($_method)
      const STORE = 1;
      const DEFLATE = 2;
      const BZIP2 = 3;
      
      // Constantes de sortie ($_out)
      const STDOUT = 3;
      const FILE = 4;
      
      function __construct($method, $onTheFly, $out, $filename='') {
         $this->setMethod($method);
         $this->setOnTheFly($onTheFly);
         $this->filename = $filename;
         $this->setOut($out);
         if ($this->method != self::STORE) {
            $this->tmpFile = $this->createTemporaryFile('CompressClassTmp');
         } else {
            $this->tmpFile = '';
         }
         $this->nbFiles = 0;
         $this->zip64 = false;
         $this->offsetLocalFileHeader = 0;
         $this->sizeCentralDirectory = 0;
         $this->centralDirectoryString = '';
         $this->arrayFiles = array();
         $this->setCompressionLevel(4);
      }
      
      function zip64() {
         return $this->zip64;
      }
      
      function method() {
         return $this->method;
      }
      
      function onTheFly() {
         return $this->onTheFly;
      }
      
      function out() {
         return $this->out;
      }
      
      function nbFiles() {
         return $this->nbFiles;
      }
      
      function setCompressionLevel($compression_level) {
         if (is_int($compression_level)) {
            if ($compression_level < 1) {
               $compression_level = 1;
            }
            if ($compression_level > 9) {
               $compression_level = 9;
            }
            $this->compressionLevel = $compression_level;
         } else {
            trigger_error('Le niveau de compression doit etre un entier entre 1 et 9 (9 est la meilleure compression)', E_USER_ERROR);
         }
      }
      
      function setOut($out) {
         if (is_int($out)) {
            if ($out == self::STDOUT || $out == self::FILE) {
               $this->out = $out;
               
               if ($this->out == self::FILE) {
                  if (!strlen($this->filename)) {
                     trigger_error('Vous devez preciser le fichier de sortie avec la sortie FILE', E_USER_ERROR);
                  } else {
                     $this->outFilePointer = fopen($this->filename,'wb');
                     if (!$this->outFilePointer) {
                        trigger_error('Impossible d\'ecrire dans le fichier : ' . $this->filename, E_USER_ERROR);
                     }
                  }
               }
            } else {
               trigger_error('La sortie doit etre une constante parmi STDOUT ou FILE', E_USER_ERROR);
            }
         } else {
            trigger_error('La sortie doit etre une constante parmi STDOUT ou FILE', E_USER_ERROR);
         }
      }
      
      function setMethod($method) {
         if (is_int($method)) {
            if ($method == self::STORE || $method == self::DEFLATE || $method == self::BZIP2) {
               $this->method = $method;
            } else {
               trigger_error('La methode de compression doit etre une constante parmi STORE, DEFLATE ou BZIP2', E_USER_ERROR);
            }
         } else {
            trigger_error('La methode de compression doit etre une constante parmi STORE, DEFLATE ou BZIP2', E_USER_ERROR);
         }
      }
      
      function setOnTheFly($onTheFly) {
         if ($onTheFly) {
            $this->onTheFly = true;
         } else {
            $this->onTheFly = false;
         }
      }
      
      function addFile($file, $filename='') {
         if ($this->onTheFly()) {
            $this->addToArchive($file, $filename);
         } else {
            $this->arrayFiles[] = array('file'=>$file,'filename'=>$filename);
         }
      }
      
      function createArchive() {
         foreach ($this->arrayFiles as $ta_file) {
            $this->addToArchive($ta_file['file'],$ta_file['filename']);
         }
      }
      
      function addToArchive($file,$filename='') {
         if (is_file($file)) {
            // Taille du fichier
            $filesize = filesize($file);
            $filesize64 = $this->convert64to32($filesize);
            
            // Compression
            if ($this->method == self::BZIP2 || $this->method == self::DEFLATE) {
               if ($this->method == self::BZIP2) {
                  $compress = new Bzip2(Bzip2::FILE, $file, Bzip2::FILE, $this->tmpFile);
                  $compress->compressionLevel = $this->compressionLevel;
                  $compress->compress();
                  unset($compress);
               } elseif ($this->method == self::DEFLATE) {
                  $compress = new Deflate(Deflate::FILE, $file, Deflate::FILE, $this->tmpFile);
                  $compress->compressionLevel = $this->compressionLevel;
                  $compress->compress();
                  unset($compress);
               }
               $compressed_filesize = filesize($this->tmpFile);
               $compressed_filesize64 = $this->convert64to32($compressed_filesize);
               $compressed_file = $this->tmpFile;
            } else {
               $compressed_filesize = $filesize;
               $compressed_filesize64 = $filesize64;
               $compressed_file = $file;
            }
            
            // Hash
            $hash = hash_file( 'crc32b', $file);
            $hash = hexdec($hash);
            
            // Mise en forme du nom du fichier
            if (!strlen($filename)) {
               //$filename = basename($file);
               $filename = $file;
            }
            $encoded_filename = iconv("UTF-8","IBM850 //IGNORE",$filename);
            
            // On supprime les slahes initiaux
            while (substr($encoded_filename, 0, 1) == '/') {
               $encoded_filename = substr($encoded_filename, 1);
            }
            
            // Longueur du nom de fichier
            $filename_length = strlen($encoded_filename);
            
            // Date/Heure du fichier
            $zipTime = $this->zipTime(time());
            
            $chaine = '';
            
            $extra_field_length_local = 0;
            
            if ($compressed_filesize == 0) {
               $compression_method = 0;
            } else {
               $compression_method = $this->method;        // Compression method (0000: store, 000c:bzip2, 0008:deflate)
            }

            if ($compression_method == 0) {
               $version = 0x000a;
            } else {
               $version = 0x0014;
            }
            
            // Local File header 1
            $chaine .= pack('V', 0x04034b50);        // Local file header signature
            $chaine .= pack('v', $version);        // Version
            $chaine .= pack('v', 0x0000);        // General purpose bit flag
            if ($compression_method == self::BZIP2) {
               $chaine .= pack('v', 0x000c);        // Compression method (0000: store, 000c:bzip2, 0008:deflate)
            } elseif ($compression_method == self::DEFLATE) {
               $chaine .= pack('v', 0x0008);        // Compression method (0000: store, 000c:bzip2, 0008:deflate)
            } else {
               $chaine .= pack('v', 0x0000);        // Compression method (0000: store, 000c:bzip2, 0008:deflate)
            }
            //$chaine .= pack('v', 0x872b);        // Time modif
            //$chaine .= pack('v', 0x4064);        // Date Modif
            $chaine .= $zipTime;        // Date/heure Modif
            //$chaine .= pack('N', $hash);        // CRC32b
            $chaine .= pack('V', $hash);        // CRC32b
            if ($compressed_filesize > 0xFFFFFFFF) {
               $chaine .= pack('V', 0xFFFFFFFF);        // Compressed size
               $extra_field_length_local += 8;
            } else {
               $chaine .= pack('V', $compressed_filesize);        // Compressed size
            }
            if ($filesize > 0xFFFFFFFF) {
               $chaine .= pack('V', 0xFFFFFFFF);        // Uncompressed size
               $extra_field_length_local += 8;
            } else {
               $chaine .= pack('V', $filesize);        // Uncompressed size
            }
            $chaine .= pack('v', $filename_length);        // File name length (n)
            if ($extra_field_length_local) {
               $extra_field_length_local += 4;
               $flag_zip64 = true;
            }
            $chaine .= pack('v', $extra_field_length_local);        // Extra field length (m)
            $chaine .= $encoded_filename;    // Filename
            
            // Extra field of File Header
            if ($extra_field_length_local) {
               $chaine .= pack('v', 0x0001);        // Zip64 extended information extra field
               $chaine .= pack('v', $extra_field_length_local - 4);        // Taille du champ
               if ($compressed_filesize > 0xFFFFFFFF) {
                  $chaine .= pack('V', $compressed_filesize64['faible']);        // Compressed size poids faible
                  $chaine .= pack('V', $compressed_filesize64['fort']);        // Compressed size poids fort
               }
               if ($filesize > 0xFFFFFFFF) {
                  $chaine .= pack('V', $filesize64['faible']);        // Uncompressed size poids faible
                  $chaine .= pack('V', $filesize64['fort']);        // Uncompressed size poids fort
               }
            }
            
            if ($this->out == self::FILE) {
               fwrite($this->outFilePointer,$chaine);
               
               // Contenu du fichier
               $fp = fopen ($compressed_file, 'rb');
               while ($str = fread($fp, 8388608)) {
                  fwrite($this->outFilePointer, $str);
               }
               fclose ($fp);
            } else {
               echo $chaine;
               
               $fp = fopen ($compressed_file, 'rb');
               while ($str = fread($fp, 8388608)) {
                  echo $str;
               }
               fclose ($fp);
            }
            
            // --------------------------------
            // Remplissage du Central Directory
            // --------------------------------
            $extra_field_length_central = 0;
            if ($compressed_filesize >= 0xffffffff) {
               $extra_field_length_central += 8;
            }
            if ($filesize >= 0xffffffff) {
               $extra_field_length_central += 8;
            }
            if ($this->offsetLocalFileHeader > 0xffffffff) {
               $extra_field_length_central += 8;
            }
            if ($extra_field_length_central) {
               $extra_field_length_central += 4;
               $this->zip64 = true;
            }

            $this->centralDirectoryString .= pack('V', 0x02014b50);        // Central directory file header signature
            $this->centralDirectoryString .= pack('v', $version);        // Version made by
            $this->centralDirectoryString .= pack('v', $version);        // Version needed to extract (minimum)
            $this->centralDirectoryString .= pack('v', 0x0000);        // General purpose bit flag
            if ($compression_method == self::BZIP2) {
               $this->centralDirectoryString .= pack('v', 0x000c);        // Compression method (0000: store, 000c:bzip2, 0008:deflate)
            } elseif ($compression_method == self::DEFLATE) {
               $this->centralDirectoryString .= pack('v', 0x0008);        // Compression method (0000: store, 000c:bzip2, 0008:deflate)
            } else {
               $this->centralDirectoryString .= pack('v', 0x0000);        // Compression method (0000: store, 000c:bzip2, 0008:deflate)
            }
            $this->centralDirectoryString .= $zipTime;              // Date Time modif
            //$this->centralDirectoryString .= pack('V', $tmp_CRC32);        // CRC32b
            //$this->centralDirectoryString .= pack('N', $hash);        // CRC32b
            $this->centralDirectoryString .= pack('V', $hash);        // CRC32b
            if ($compressed_filesize >= 0xffffffff) {        // Compressed size
               $this->centralDirectoryString .= pack('V', 0xFFFFFFFF);
            } else {
               $this->centralDirectoryString .= pack('V', $compressed_filesize);
            }
            if ($filesize >= 0xffffffff) {     // Uncompressed size
               $this->centralDirectoryString .= pack('V', 0xFFFFFFFF);
            } else {
               $this->centralDirectoryString .= pack('V', $filesize);
            }
            $this->centralDirectoryString .= pack('v', $filename_length);        // File name length (n)
            $this->centralDirectoryString .= pack('v', $extra_field_length_central);        // Extra field length (m)
            $this->centralDirectoryString .= pack('v', 0x0000);        // File comment length (k)
            $this->centralDirectoryString .= pack('v', 0x0000);        // Disk number where file starts
            $this->centralDirectoryString .= pack('v', 0x0000);        // Internal file attributes
            $this->centralDirectoryString .= pack('V', 0x00000020);   // External file attributes
            if ($this->offsetLocalFileHeader >= 0xffffffff) {  // Relative offset of local file header. This is the number of bytes between the start of the first disk on which the file occurs, and the start of the local file header
               $this->centralDirectoryString .= pack('V', 0xffffffff);
            } else {
               $this->centralDirectoryString .= pack('V', $this->offsetLocalFileHeader);
            }
            $this->centralDirectoryString .= $encoded_filename;    // Filename

            // Extra field of central directory file header 1
            if ($extra_field_length_central) {
               $this->centralDirectoryString .= pack('v', 0x0001);        // Zip64 extended information extra field
               $this->centralDirectoryString .= pack('v', ($extra_field_length_central - 4));        // Taille du champ
            }
            if ($filesize >= 0xffffffff) {
               $this->centralDirectoryString .= pack('V', $filesize64['faible']);        // Uncompressed size poids faible
               $this->centralDirectoryString .= pack('V', $filesize64['fort']);        // Uncompressed size poids fort
            }
            if ($compressed_filesize >= 0xffffffff) {
               $this->centralDirectoryString .= pack('V', $compressed_filesize64['faible']);        // Compressed size poids faible
               $this->centralDirectoryString .= pack('V', $compressed_filesize64['fort']);        // Compressed size poids fort
            }
            if ($this->offsetLocalFileHeader >= 0xffffffff) {
               $offset_local_file_header64 = $this->convert64to32($this->offsetLocalFileHeader);
               $this->centralDirectoryString .= pack('V', $offset_local_file_header64['faible']);        // Offset of local header record poids faible
               $this->centralDirectoryString .= pack('V', $offset_local_file_header64['fort']);        // Offset of local header record poids fort
            }
            
            $this->offsetLocalFileHeader += (30 + $filename_length + $extra_field_length_local + $compressed_filesize);
            $this->sizeCentralDirectory += (46 + $filename_length + $extra_field_length_central);
            
            $this->nbFiles++;
         } else {
            trigger_error('Le fichier est introuvable : ' . $file, E_USER_ERROR);
         }
      }
      
      function finalizeArchive() {
         if ($this->nbFiles >= 0xffffffff) {
            $this->zip64 = true;
         }
         
         $count_files64 = $this->convert64to32($this->nbFiles);
         $size_central_directory64 = $this->convert64to32($this->sizeCentralDirectory);
         $offset_start_central_directory64 = $this->convert64to32($this->offsetLocalFileHeader);
         
         // Préparation du End of central directory record (ZIP64) si nécessaire
         if ($this->zip64) {
            $size_of_zip64_end_of_CDR = $this->convert64to32(56 - 12);

            $this->centralDirectoryString .= pack('V', 0x06064b50);        // End of central directory signature
            $this->centralDirectoryString .= pack('V', $size_of_zip64_end_of_CDR['faible']);        // size of zip64 end of central directory record poids faible (Size = SizeOfFixedFields + SizeOfVariableData - 12)
            $this->centralDirectoryString .= pack('V', $size_of_zip64_end_of_CDR['fort']);        // size of zip64 end of central directory record poids fort (Size = SizeOfFixedFields + SizeOfVariableData - 12)
            $this->centralDirectoryString .= pack('v', 0x002d);        // Version made by
            $this->centralDirectoryString .= pack('v', 0x002d);        // Version needed to extract (minimum)
            $this->centralDirectoryString .= pack('V', 0);        // Number of this disk
            $this->centralDirectoryString .= pack('V', 0);        // number of the disk with the start of the central directory
            $this->centralDirectoryString .= pack('V', $count_files64['faible']);        // total number of entries in the central directory on this disk poids faible
            $this->centralDirectoryString .= pack('V', $count_files64['fort']);          // total number of entries in the central directory on this disk poids fort
            $this->centralDirectoryString .= pack('V', $count_files64['faible']);        // total number of entries in the central directory poids faible
            $this->centralDirectoryString .= pack('V', $count_files64['fort']);          // total number of entries in the central directory poids fort
            $this->centralDirectoryString .= pack('V', $size_central_directory64['faible']);        // size of the central directory poids faible
            $this->centralDirectoryString .= pack('V', $size_central_directory64['fort']);          // size of the central directory poids fort
            $this->centralDirectoryString .= pack('V', $offset_start_central_directory64['faible']);        // offset of start of central directory with respect to the starting disk number poids faible
            $this->centralDirectoryString .= pack('V', $offset_start_central_directory64['fort']);          // offset of start of central directory with respect to the starting disk number poids fort
         }
         
         // Préparation du End of central directory locator (ZIP64) si nécessaire
         if ($this->zip64) {
            $relative_offset_zip64_end_of_CDR64 = $this->convert64to32($this->offsetLocalFileHeader + $this->sizeCentralDirectory);

            $this->centralDirectoryString .= pack('V', 0x07064b50);        // Zip64 end of central directory locator signature
            $this->centralDirectoryString .= pack('V', 0);        // Number of the disk with the start of the zip64 end of central directory
            $this->centralDirectoryString .= pack('V', $relative_offset_zip64_end_of_CDR64['faible']);     // relative offset of the zip64 end of central directory record
            $this->centralDirectoryString .= pack('V', $relative_offset_zip64_end_of_CDR64['fort']);     // relative offset of the zip64 end of central directory record
            $this->centralDirectoryString .= pack('V', 1);        // total number of disks
         }
         
         // Préparation du End of central directory record
         if ($this->nbFiles >= 0xffffffff) {
            $use_count_files = 0xffffffff;
         } else {
            $use_count_files = $this->nbFiles;
         }
         if ($this->sizeCentralDirectory >= 0xffffffff) {
            $use_size_central_directory = 0xffffffff;
         } else {
            $use_size_central_directory = $this->sizeCentralDirectory;
         }
         if ($this->offsetLocalFileHeader >= 0xffffffff) {
            $use_offset_start_central_directory = 0xffffffff;
         } else {
            $use_offset_start_central_directory = $this->offsetLocalFileHeader;
         }
         $this->centralDirectoryString .= pack('V', 0x06054b50);        // End of central directory signature
         $this->centralDirectoryString .= pack('v', 0);        // Number of this disk
         $this->centralDirectoryString .= pack('v', 0);        // Disk where central directory starts
         $this->centralDirectoryString .= pack('v', $use_count_files);        // Number of central directory records on this disk
         $this->centralDirectoryString .= pack('v', $use_count_files);        // Total number of central directory records
         $this->centralDirectoryString .= pack('V', $use_size_central_directory);        // Size of central directory (bytes)
         $this->centralDirectoryString .= pack('V', $use_offset_start_central_directory);        // Offset of start of central directory, relative to start of archive
         $this->centralDirectoryString .= pack('v', 0x0000);            // Comment length
         
         if ($this->out == self::FILE) {
            fwrite($this->outFilePointer,$this->centralDirectoryString);
            fclose ($this->outFilePointer);
         } else {
            echo $this->centralDirectoryString;
         }
         
         if ($this->method != self::STORE) {
            if (is_file($this->tmpFile)) {
               unlink($this->tmpFile);
            }
         }
      }
      
      // Fichier temporaire
      function createTemporaryFile($name) {
         $file = DIRECTORY_SEPARATOR .
                 trim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) .
                 DIRECTORY_SEPARATOR .
                 ltrim($name, DIRECTORY_SEPARATOR);

         if (is_file($file)) {
            unlink($file);
         }
         
         touch($file);

         register_shutdown_function(function() use($file) {
            if (is_file($file)) {
               unlink($file);
            }
         });
         
         return $file;
      }
      
      // Conversion d'un nombre 64 bits en 2 de 32
      function convert64to32($nb64) {
         $return = array();
         $return['fort'] = hexdec(substr(dechex($nb64), -16, -8));
         $return['faible'] = hexdec(substr(dechex($nb64), -8));
         return $return;
      }
      
      // Conversion d'un Time UNIX en Time DOS pour ZIP
      function zipTime($time = 0) {
         if ($time == 0) {
            $time = time();
         }
         $timearray = getdate($time);

         if ($timearray['year'] < 1980) {
            $timearray['year']    = 1980;
            $timearray['mon']     = 1;
            $timearray['mday']    = 1;
            $timearray['hours']   = 0;
            $timearray['minutes'] = 0;
            $timearray['seconds'] = 0;
         }

         $time = dechex((($timearray['year'] - 1980) << 25) | ($timearray['mon'] << 21) | ($timearray['mday'] << 16) | ($timearray['hours'] << 11) | ($timearray['minutes'] << 5) | ($timearray['seconds'] >> 1));
         $hextime = '\x' . $time[6] . $time[7] . '\x' . $time[4] . $time[5] . '\x' . $time[2] . $time[3] . '\x' . $time[0] . $time[1];
         eval('$hextime = "' . $hextime . '";');

         return $hextime;
      }
      
   }
?>
