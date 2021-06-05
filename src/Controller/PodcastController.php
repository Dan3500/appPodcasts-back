<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Services\JwtAuth;
use Knp\Component\Pager\PaginatorInterface;
use App\Kernel;

use App\Entity\User;
use App\Entity\Podcast;
use ProxyManager\Factory\RemoteObject\Adapter\JsonRpc;

use Symfony\Component\Routing\Annotation\Route;
use App\Services\FileUploader;
use Psr\Log\LoggerInterface;

class PodcastController extends AbstractController
{
    /**
     * Serializar el objeto a un JSON para poder enviarlo correctamente a un Front-End
     * @param data: Object/Array Objeto que se quiere serializar
     * @return response: JSON con los datos del objeto que se ha serializado
     */
    private function resjson($data){
        $json=$this->get('serializer')->serialize($data,'json');
        $response=new Response();
        $response->setContent($json);
        $response->headers->set('Content-Type','application/json');
        return $response;
    }

    /**
     * Metodo para almacenar un nuevo podcast en la base de datos
     * @param request: Request Objeto Podcast con los datos que se van a almacenar en la base de datos
     * @param jwtAuth: JwtAuth Objeto del JSON Web Token que contiene la key y el manager para crear el token
     * @return data: JSON con el resultado del almacenamiento de los datos del podcast en la base de datos
     */
    public function createPodcast(Request $request, JwtAuth $jwtAuth){
        //Recoger el token y comprobar si el usuario tiene permisos para almacenar el podcast
        $token=$request->headers->get("Authorization",null);
        $authCheck=$jwtAuth->checkToken($token);
        if ($authCheck){
            //Recoger los datos enviados desde el front-end, decodificarlos y obtener la identidad del usuario del token
            $json=$request->get("json",null);
            $params=json_decode($json);
            $identity=$jwtAuth->checkToken($token,true);

            if ($json!=null){
                $user_id=$identity->user_id;
                $title=(!empty($params->title)) ? $params->title : null;
                $audio=(!empty($params->audio)) ? $params->audio: null;
                $img=(!empty($params->image)) ? $params->image : "defaultPodcast.png";
                //Si se recogen los datos y los datos son válidos, se creará el objeto de podcast
                if (!empty($title)&&!empty($audio)&&!empty($user_id)){
                    $em=$this->getDoctrine()->getManager();
                    $user=$this->getDoctrine()->getRepository(User::class)->findOneBy(["id"=>$user_id]);
                    //Crear el podcast que se va a insertar en la base de datos
                    $podcast=new Podcast();
                    $podcast->setAudio($audio);
                    $podcast->setTitle($title);
                    $podcast->setImage($img);
                    $podcast->setCreatedAt(new \DateTime('now'));
                    $podcast->setUpdatedAt(new \DateTime('now'));
                    $podcast->setUser($user);
                    //Se registrará el podcast en la base de datos
                    $em->persist($podcast);
                    $em->flush();
    
                    $data=[
                        "status"=>"success",
                        "code"=>200,
                        "message"=>"Podcast agregado con éxito",
                    ];
                }else{
                    $data=[
                        "status"=>"error",
                        "code"=>400,
                        "message"=>"Los datos introducidos son incorrectos",
                    ];
                }
            }else{
                $data=[
                    "status"=>"error",
                    "code"=>400,
                    "message"=>"Error al recibir los datos, inténtalo de nuevo más tarde"
                ];
            }
        }else{
            $data=[
                "status"=>"error",
                "code"=>403,
                "message"=>"No tienes permiso para llevar a cabo esta acción"
            ];
        }
        return $this->resjson($data);
    }

    /**
     * Metodo para subir un archivo de un podcast (Audio o Imagen) y almacenarlo en el servidor
     * @param request: Request [POST] que llega desde el Front, contiene los archivos que se van a guardar en el servidor
     * @param FileUploader: FileUploader Objeto que subirá el archivo al servidor
     * @param uploadDir: String Contiene la ruta donde está instalado el projecto
     * @return data: JSON con el resultado de la subida de archivos
     */
    public function uploadFile(Request $request, FileUploader $uploader, string $uploadDir){
        //Se recoge el token y el archivo
        $token = $request->headers->get("Authorization",null);
        $file=$request->files->get("file0");
        if(!$file){
            $file=$request->files->get("file");
        }
        if (empty($file)||$token==null)
        {
            $data=[
                "code"=>400,
                "file"=>"ERROR",
                "name"=>"ERROR",
                "file"=>$file
            ]; 
        }else{
            //Se crea un nombre unico para el archivo y se sube
            $filename=time().$file->getClientOriginalName();
            $uploader->upload($uploadDir, $file, $filename);
            $data=[
                "code"=>200,
                "name"=>$filename
            ]; 
        }
        return $this->resjson($data);
    }

    /**
     * Metodo para obtener todos los podcast de la base de datos y los datos necesarios para su
     * paginación en el front
     * @param request: Request [DELETE] que llega desde el Front, contiene el id del podcast que se va a eliminar
     * @param jwtAuth: JwtAuth Objeto del JSON Web Token que contiene la key y el manager para crear el token
     * @param paginator: Paginator Objeto que crea la lista para la páginación en el front, según los items que se
     * van a mostrar en cada página y en la página en la que se encuentra
     * @return data: JSON Resultado de la petición que se devolverá al front.
     */
    public function listPodcast(Request $request, JwtAuth $jwtAuth,PaginatorInterface $paginator){
       
       $token=$request->headers->get("Authorization",null);
       $authCheck=$jwtAuth->checkToken($token);

       if ($authCheck){//Se comprueba que el usuario tenga acceso al home para ver el listado de podcast
            $em=$this->getDoctrine()->getManager();
            $dql="SELECT p FROM App\Entity\Podcast p ORDER BY p.id DESC";
            $query=$em->createQuery($dql);//Se obtienen todos vídeos que se encuentran en la base de datos

            //Se obtiene el parametro de la url (página en la que se encuentra el usuario)
            $pag=$request->query->getInt('page',1);
            $items_per_pag=3;

            //Se crea la página
            $pagination=$paginator->paginate($query,$pag,$items_per_pag);
            $total=$pagination->getTotalItemCount();//Se obtiene el número total de páginas

            //Si se recibe de la URL un número mayor al total, se volverá a hacer la páginación en la página uno
            //(Para evitar que se muestren páginas sin ningún contenido)
            if ($pag>ceil($total/$items_per_pag)){
                $pag=1;
                $pagination=$paginator->paginate($query,$pag,$items_per_pag);
            }
            
            $data=[
                "status"=>"success",
                "code"=>200,
                "total_items_count"=>$total,//Número total de podcast listados
                "pag_actual"=>$pag,//Página actual donde se encuentra el usuario
                "items_per_pag"=>$items_per_pag,//Items en cada página, en este caso 4
                "total_pages"=>ceil($total/$items_per_pag),//Páginas totales que forman el listado
                "podcasts"=>$pagination//La información de los podcast
            ];
       }else{
        $data=[
            "status"=>"error",
            "code"=>403,
            "message"=>"Error al obtener todos los podcasts, el token del usuario no es válido"
        ];
       }
      

       return $this->resjson($data);
    }


    /**
     * Metodo para obtener todos los podcast de la base de datos de un usuario concreto y los datos necesarios para su
     * paginación en el front
     * @param request: Request [DELETE] que llega desde el Front, contiene el id del podcast que se va a eliminar
     * @param jwtAuth: JwtAuth Objeto del JSON Web Token que contiene la key y el manager para crear el token
     * @param paginator: Paginator Objeto que crea la lista para la páginación en el front, según los items que se
     * van a mostrar en cada página y en la página en la que se encuentra
     * @return data: JSON Resultado de la petición que se devolverá al front.
     */
    public function listUserPodcast(Request $request, JwtAuth $jwtAuth,PaginatorInterface $paginator,$id=null,$page=1){
        $token=$request->headers->get("Authorization",null);
        $authCheck=$jwtAuth->checkToken($token);
        
        if ($authCheck){//Se comprueba que el usuario tenga acceso al home para ver el listado de podcast
            $em=$this->getDoctrine()->getManager();
            $dql="SELECT p FROM App\Entity\Podcast p WHERE p.user = {$id} ORDER BY p.id DESC";
            $query=$em->createQuery($dql);//Se obtienen todos vídeos que se encuentran en la base de datos

            //Se obtiene el parametro de la url (página en la que se encuentra el usuario)
            $items_per_pag=3;
            
            //Se crea la página
            $pagination=$paginator->paginate($query,$page,$items_per_pag);
            $total=$pagination->getTotalItemCount();//Se obtiene el número total de páginas

            //Si se recibe de la URL un número mayor al total, se volverá a hacer la páginación en la página uno
            //(Para evitar que se muestren páginas sin ningún contenido)
            if ($page>ceil($total/$items_per_pag)){
                $page=1;
                $pagination=$paginator->paginate($query,$page,$items_per_pag);
            }
            
            $data=[
                "status"=>"success",
                "code"=>200,
                "total_items_count"=>$total,//Número total de podcast listados
                "pag_actual"=>$page,//Página actual donde se encuentra el usuario
                "items_per_pag"=>$items_per_pag,//Items en cada página, en este caso 4
                "total_pages"=>ceil($total/$items_per_pag),//Páginas totales que forman el listado
                "podcasts"=>$pagination//La información de los podcast
            ];
        }else{
         $data=[
             "status"=>"error",
             "code"=>403,
             "message"=>"Error al obtener todos los podcasts, el token del usuario no es válido"
         ];
        }
       
 
        return $this->resjson($data);
     }

    /**
     * Elimina un podcast de la base de datos
     * @param request: Request [DELETE] que llega desde el Front, contiene el id del podcast que se va a eliminar
     * @param jwtAuth: JwtAuth Objeto del JSON Web Token que contiene la key y el manager para crear el token
     * @param id: Int=null Id del podcast que se va a eliminar
     * @return data: JSON Resultado de la petición que se devolverá al front.
     */
    public function removePodcast(Request $request, JwtAuth $jwtAuth, $id=null){
        $token=$request->headers->get("Authorization",null);
        $auth=$jwtAuth->checkToken($token);
        if ($auth){//Si el usuario se encuentra conectado en la página
            $identity=$jwtAuth->checkToken($token,true);
            $doctrine=$this->getDoctrine();
            $em=$doctrine->getManager();
            $podcast=$doctrine->getRepository(Podcast::class)->findOneBy(["id"=>$id]);
            //Se encuentra el podcast y se comprueba que el propietario del podcast sea el mismo usuario que lo va a eliminar
            if (is_object($podcast)&&!empty($podcast) && $identity->user_id==$podcast->getUser()->getId()){
                $fileAud=$podcast->getAudio();
                $fileImg=$podcast->getImage();
                //Eliminar los archivos del podcasts
                if ($fileImg!="defaultPodcast.png"){
                    unlink("../public/uploads/images/podcast/".$fileImg);
                }
                unlink("../public/uploads/audios/".$fileAud);
                $em->remove($podcast);
                $em->flush();//Se borra de la base de datos
                $data=[
                    "status"=>"success",
                    "code"=>200,
                    "message"=>"Podcast '{$podcast->getTitle()}' eliminado exitosamente"
                ];
            }else{
                $data=[
                    "status"=>"error",
                    "code"=>404,
                    "message"=>"Error al eliminar el podcast, no existe el podcast que quieres eliminar"
                ];
            }
        }else{
            $data=[
                "status"=>"error",
                "code"=>403,
                "message"=>"Error al eliminar el podcast, no tienes permisos para eliminar este podcast"
            ];
        }

        return $this->resjson($data);
    }

    /**
     * Metodo para obtener toda la información de un podcast
     * @param request: Datos de la petición
     * @param jwtAuth: JwtAuth Objeto del JSON Web Token que contiene la key y el manager para crear el token
     * @param id: Int=null Id del podcast que se va a obtener
     * @return data: JSON Resultado de la petición que se devolverá al front.
     */
    public function getPodcast(Request $request, JwtAuth $jwtAuth, $id=null){
        $token=$request->headers->get("Authorization",null);
        $auth=$jwtAuth->checkToken($token);
        if ($auth){//Si el usuario se encuentra conectado en la página
            $doctrine=$this->getDoctrine();
            $podcast=$doctrine->getRepository(Podcast::class)->findOneBy(["id"=>$id]);
            //Se encuentra el podcast y se comprueba que el propietario del podcast sea el mismo usuario que lo va a eliminar
            if (is_object($podcast)&&!empty($podcast)){
                //Se obtiene de la base de datos
                $data=[
                    "status"=>"success",
                    "code"=>200,
                    "message"=>"Podcast obtenido con exito",
                    "podcast"=>$podcast
                ];
            }else{
                $data=[
                    "status"=>"error",
                    "code"=>404,
                    "message"=>"Error al obtener el podcast, no existe el podcast que quieres ver"
                ];
            }
        }else{
            $data=[
                "status"=>"error",
                "code"=>403,
                "message"=>"Error, no tienes permisos para ver este podcast"
            ];
        }
        return $this->resjson($data);
    }

    /**
     * Metodo para editar un podcast ya introducido en la base de datos
     * @param request: Datos de la petición
     * @param jwtAuth: JwtAuth Objeto del JSON Web Token que contiene la key y el manager para crear el token
     * @return data: JSON Resultado de la petición que se devolverá al front.
     */
    public function editPodcast(Request $request, JwtAuth $jwtAuth){
        //Recoger el token y comprobar si el usuario tiene permisos para almacenar el podcast
        $token=$request->headers->get("Authorization",null);
        $authCheck=$jwtAuth->checkToken($token);
        if ($authCheck){
            //Recoger los datos enviados desde el front-end, decodificarlos y obtener la identidad del usuario del token
            $json=$request->get("json",null);
            $params=json_decode($json);
            $identity=$jwtAuth->checkToken($token,true);

            if ($json!=null){
                $user_id=$identity->user_id;
                $title=(!empty($params->title)) ? $params->title : null;
                $audio=(!empty($params->audio)) ? $params->audio: null;
                $img=(!empty($params->image)) ? $params->image : "defaultPodcast.png";
                //Si se recogen los datos y los datos son válidos, se creará el objeto de podcast
                if (!empty($title)&&!empty($audio)&&!empty($user_id)){
                    $em=$this->getDoctrine()->getManager();
                    $podcast=$this->getDoctrine()->getRepository(Podcast::class)->findOneBy(["id"=>$params->id]);
                    //Crear el podcast que se va a insertar en la base de datos
                    $podcast->setAudio($audio);
                    $podcast->setTitle($title);
                    $podcast->setImage($img);
                    $podcast->setUpdatedAt(new \DateTime('now'));
                    //Se comprobará si el usuario que modifica el podcast es propietario de el
                    if ($user_id==$podcast->getUser()->getId()){
                        $em->persist($podcast);
                        $em->flush();
                        $data=[
                            "status"=>"success",
                            "code"=>200,
                            "message"=>"Podcast modificado con éxito",
                        ];
                    }else{
                        $data=[
                            "status"=>"error",
                            "code"=>403,
                            "message"=>"No tienes permiso para llevar a cabo esta acción"
                        ];
                    }
                }else{
                    $data=[
                        "status"=>"error",
                        "code"=>400,
                        "message"=>"Los datos introducidos son incorrectos",
                    ];
                }
            }else{
                $data=[
                    "status"=>"error",
                    "code"=>400,
                    "message"=>"Error al recibir los datos, inténtalo de nuevo más tarde"
                ];
            }
        }else{
            $data=[
                "status"=>"error",
                "code"=>403,
                "message"=>"No tienes permiso para llevar a cabo esta acción"
            ];
        }
        return $this->resjson($data);
    }
    
    /**
     * Métodos para obtener los archivos de imagen y el audio de los podcast
     * @param filename: String Nombre del archivo que se quiere recoger (se encuentra en la base de datos)
     * @param projectDir: String Contiene la ruta donde está instalado el projecto
     * @return Response: Devuelve el archivo para mostrarlo en el front
     */
    public function getImg($filename,string $projectDir){
        $publicResourcesFolderPath=$projectDir;
        $file="public\\uploads\\images\\podcast\\".$filename;
        return new BinaryFileResponse($publicResourcesFolderPath."\\".$file);
    }

    public function getAudio($filename,string $projectDir){
        $publicResourcesFolderPath=$projectDir;
        $file="public\\uploads\\audios\\".$filename;
        return new BinaryFileResponse($publicResourcesFolderPath."\\".$file);
    }

}
