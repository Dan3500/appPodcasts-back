<?php
namespace App\Services;

use Symfony\Component\HttpFoundation\File\Exception\FileException;

class FileUploader
{
    /**
     * Metodo para subir un archivo al servidor
     * @param uploadDir: String Contiene la ruta donde está instalado el projecto
     * @param file: Archivo que se va a subir
     * @param filename: Nombre del archivo con el que se va a subir al servidor
     */
    public function upload($uploadDir, $file, $filename)
    {
        //Se comprueba el tipo de archivo (audio o imagen)
        $extension=explode(".",$filename);
        if ($extension[count($extension)-1]=="mp3"){
            $path="/audios/";
        }else{
            $path="/images/podcast";
        }
        try {
            $file->move($uploadDir.$path,$filename);//Se sube al servidor
        } catch (FileException $e){
            throw new FileException('Error al subir el archivo al servidor');
        }
    }

    /**
     * Metodo para subir un archivo de imagen de usuario al servidor
     * @param uploadDir: String Contiene la ruta donde está instalado el projecto
     * @param file: Archivo que se va a subir
     * @param filename: Nombre del archivo con el que se va a subir al servidor
     */
    public function uploadImgUser($uploadDir, $file, $filename)
    {
        $path="/images/user";
        try {
            $file->move($uploadDir.$path,$filename);//Se sube al servidor
        } catch (FileException $e){
            throw new FileException('Error al subir el archivo al servidor');
        }
    }
}