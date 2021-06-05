<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Bundle\MakerBundle\Validator;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validation;
use App\Services\JwtAuth;

use App\Entity\User;
use App\Services\FileUploader;
use App\Entity\Podcast;
use ProxyManager\Factory\RemoteObject\Adapter\JsonRpc;

class UserController extends AbstractController
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
     * REGISTRO DE USUARIOS
     * Método para registrar un nuevo usuario en la base de datos y devolver el resultado al Front
     * @param request: Request [POST] que llega desde el Front, contiene los datos que se van a insertar
     * @return data: Datos en un array convertidos en un JSON
     */
    public function register(Request $request)
    {
        //Recoger los datos enviados desde el front-end y decodificarlos
        $json=$request->get("json",null);
        $params=json_decode($json);

        if ($json!=null){
            $username=(!empty($params->username)) ? $params->username : null;
            $email=(!empty($params->email)) ? $params->email : null;
            $password=(!empty($params->password)) ? $params->password : null;

            $validator=Validation::createValidator();
            $validate_email=$validator->validate($email,[new Email()]);

            if (!empty($email)&&count($validate_email)==0
                &&!empty($password)&&!empty($username)){
                     //Crear el usuario que se va a insertar en la base de datos
                    $user=new User();
                    $user->setUsername($username);
                    $user->setEmail($email);
                    $user->setPassword(hash('sha256',$password));
                    $user->setCreatedAt(new \DateTime('now'));
                    $user->setImage("defaultUser.png");
                    
                    //Se obtiene el repositorio de usuarios de la base de datos para comprobar el registro
                    $doctrine=$this->getDoctrine();
                    $em=$doctrine->getManager();

                    $user_repo=$doctrine->getRepository(User::class);

                    $isset_user=$user_repo->findOneBy(array("email"=>$user->getEmail()));
                    //Comprobar si usuario existe ya en la base de datos
                    if ($isset_user==null){
                        //Si no existe, se registra al usuario en la base de datos
                        $em->persist($user);
                        $em->flush();

                        $data=[
                            "status"=>"success",
                            "code"=>200,
                            "message"=>"Usuario registrado con éxito",
                        ];
                    }else{
                        $data=[
                            "status"=>"error",
                            "code"=>400,
                            "message"=>"Error al insertar el usuario, ya hay un usuario registrado con este email",
                        ];
                    }
            }else{
                $data=[
                    "status"=>"error",
                    "code"=>400,
                    "message"=>"Validacion de datos incorrecta",
                ];
            }
        }else{
            $data=[
                "status"=>"error",
                "code"=>400,
                "message"=>"Error al recibir los datos"
            ];
        }
        
        //Enviar el resultado al cliente
        return $this->resjson($data);
    }

    /**
     * INICIO DE SESIÓN DE USUARIOS
     * Metodo para loguear a un usuario en la página y generar un JWT
     * @param request: Request [POST] que llega desde el Front que contiene los datos que se van comprobar
     * @param jwtAuth: JwtAuth Objeto del JSON Web Token que contiene la key y el manager para crear el token
     * @return data: Array|JSON Resultado de la petición que se devolverá al front. Puede ser un Array con el
     * resultado de la peticion, el JWT o los datos del usuario
     */
    public function login(Request $request, JwtAuth $jwtAuth){
        //Recoger los datos enviados desde el front-end y decodificarlos
        $json=$request->get('json',null);
        $params=json_decode($json);

        if ($json!=null){
            //Se validan los datos que se reciben del cliente
            $email=(!empty($params->email)) ? $params->email : null;
            $password=(!empty($params->password)) ? $params->password : null;
            $getToken=$params->getToken;

            $validator=Validation::createValidator();
            $validate_email=$validator->validate($email,[new Email()]);

            if (!empty($email)&&count($validate_email)==0&&!empty($password)){
                //Si se validan correctamente los datos, se logueara al usuario y se generará el token
                $pwd=hash('sha256',$password);
                if ($getToken){
                    $signup=$jwtAuth->signup($email,$pwd,$getToken);
                }else{
                    $signup=$jwtAuth->signup($email,$pwd);
                }

                $signup=new JsonResponse($signup);//Se convierte la respuesta del logueo a un JSON
            }else{
                $data=[
                    'status'=>'error',
                    'code'=>400,
                    'message'=>'Error, los datos no son válidos'
                ];
            }
        }else{
            $data=[
                "status"=>"error",
                "code"=>400,
                "message"=>"Error al recibir los datos"
            ];
        }

        //Enviar el resultado al cliente
        if ($signup){
            $data=$signup;
        }else{
            $data=$this->resjson($data);
        }
        return $data;
    }

    /**
     * Metodo para obtener toda la información de un usuario
     * @param request: Datos de la petición
     * @param jwtAuth: JwtAuth Objeto del JSON Web Token que contiene la key y el manager para crear el token
     * @param id: Int=null Id del podcast que se va a obtener
     * @return data: JSON Resultado de la petición que se devolverá al front.
     */
    public function getUserData(Request $request, JwtAuth $jwtAuth, $id=null){
        $token=$request->headers->get("Authorization",null);
        $auth=$jwtAuth->checkToken($token);
        if ($auth){//Si el usuario se encuentra conectado en la página
            $doctrine=$this->getDoctrine();
            $user=$doctrine->getRepository(User::class)->findOneBy(["id"=>$id]);
            //Se encuentra el podcast y se comprueba que el propietario del podcast sea el mismo usuario que lo va a eliminar
            if (is_object($user)&&!empty($user)){
                //Se obtiene de la base de datos
                $data=[
                    "status"=>"success",
                    "code"=>200,
                    "message"=>"Usuario obtenido con exito",
                    "user"=>$user
                ];
            }else{
                $data=[
                    "status"=>"error",
                    "code"=>404,
                    "message"=>"Error al obtener el usuario, no existe el usuario que estas buscando",
                    "id"=>$id
                ];
            }
        }else{
            $data=[
                "status"=>"error",
                "code"=>403,
                "message"=>"Error, no tienes permisos"
            ];
        }
        return $this->resjson($data);
    }

    /**
     * Metodo para subir una imagen de usuario y almacenarlo en el servidor
     * @param request: Request [POST] que llega desde el Front, contiene el archivo que se va a guardar en el servidor
     * @param FileUploader: FileUploader Objeto que subirá el archivo al servidor
     * @param uploadDir: String Contiene la ruta donde está instalado el projecto
     * @return data: JSON con el resultado de la subida de archivos
     */
    public function uploadUserImg(Request $request, FileUploader $uploader, string $uploadDir){
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
            $uploader->uploadImgUser($uploadDir, $file, $filename);
            $data=[
                "code"=>200,
                "name"=>$filename
            ]; 
        }
        return $this->resjson($data);
    }

    /**
     * 
     */
    public function editUserData(Request $request, JwtAuth $jwtAuth){
        //Recoger el token y comprobar si el usuario tiene permisos para modificar los datos
        $token=$request->headers->get("Authorization",null);
        $authCheck=$jwtAuth->checkToken($token);
        if ($authCheck){
            //Recoger los datos enviados desde el front-end, decodificarlos y obtener la identidad del usuario del token
            $json=$request->get("json",null);
            $params=json_decode($json);
            $identity=$jwtAuth->checkToken($token,true);

            if ($json!=null){
                $user_id=$identity->user_id;
                $username=(!empty($params->username)) ? $params->username : null;
                $img=(!empty($params->image)) ? $params->image : "defaultUser.png";
                //Si se recogen los datos y los datos son válidos, se creará el objeto de podcast
                if (!empty($username)&&!empty($img)&&!empty($user_id)){
                    $em=$this->getDoctrine()->getManager();
                    $user=$this->getDoctrine()->getRepository(User::class)->findOneBy(["id"=>$params->id]);
                    //Crear el podcast que se va a insertar en la base de datos
                    $user->setUsername($username);
                    $user->setImage($img);
                    //Se comprobará si el usuario que modifica el podcast es propietario de el
                    if ($user_id==$user->getId()){
                        $em->persist($user);
                        $em->flush();
                        $data=[
                            "status"=>"success",
                            "code"=>200,
                            "message"=>"Usuario modificado con éxito",
                            "user"=>$user
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
    public function getImgUser($filename,string $projectDir){
        $publicResourcesFolderPath=$projectDir;
        $file="public\\uploads\\images\\user\\".$filename;
        return new BinaryFileResponse($publicResourcesFolderPath."\\".$file);
    }
}
