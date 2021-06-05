<?php
namespace App\Services;

use Firebase\JWT\JWT;
use App\Entity\User;

//CLASE DE JSON web token
class JwtAuth{

    public $manager;
    private $key="98652754sEcReTkEyToPaSsWoRd81538725";//Key para generar el token

    public function __construct($manager){
        $this->manager=$manager; 
    }
     
    /**
     * Metodo para loguear al usuario en la página y generar un token con sus datos
     * @param email: String email del usuario
     * @param password: String contraseña del usuario
     * @param getToken: Boolean=false indica si se devuelve el token del usuario o los datos del usuario
     * True=Devuelve el token | False=Devuelve los datos del usuario
     * @return data: String|Array Informacion que se va a enviar al token:
     * datos de la request, token del usuario o datos del usuario
     */
    public function signup($email,$password,$getToken=false){
        //Se comprueba si existe un usuario registrado con los datos recibidos
        $user=$this->manager->getRepository(User::class)->findOneBy([
            'email'=>$email,
            'password'=>$password
        ]);
        
        if (is_object($user)){
            //Si existe el usuario, se genera un token con sus datos
            $tokenData=[
                'user_id'=>$user->getId(),
                'username'=>$user->getUsername(),
                'email'=>$user->getEmail(),
                'image'=>$user->getImage(),
                'created_at'=>$user->getCreatedAt(),
                'iat'=>time(),
                'exp'=>time()+(7*24*3600)//El token caduca en una semana
            ];
            $jwt=JWT::encode($tokenData,$this->key,'HS256');

            if ($getToken){
                $data=$jwt;//Se devuelve solo el token
            }else{
                $decoded=JWT::decode($jwt,$this->key,['HS256']);
                $data=$decoded;//Se devuelven los datos del usuario
            }
        }else{
            $data=[
                "status"=>"error",
                "code"=>400,
                "message"=>"Error, no coinciden las credenciales"
            ];
        }
        //Devolver datos
        return $data;
    }

    /**
     * Metodo para comprobar si hay un usuario conectado en la página, es decir, tiene un token generado
     * @param jwt: String Token del usuario
     * @param identity: Boolean=false Indica si se quieren devolver los datos del usuario en vez de la
     * comprobación del token True=Devolver los datos del usuario que hay en el token | False=Devolver
     * la comprobación del token
     * @return return: Boolean|Any Devuelve el resultado de la comprobación o los datos del usuario
     */
    public function checkToken($jwt,$identity=false){
        $auth=false;
        try{
            //Se intenta decodificar el token
            $decoded=JWT::decode($jwt,$this->key,['HS256']);
        }catch(\UnexpectedValueException $e){
            $auth=false;
        }catch(\DomainException $e){
            $auth=false;
        }
    
        if ($identity==true){
            $return=$decoded;
        }else{
            //Si contiene datos, la comprobación será positiva
            if (isset($decoded)&&!empty($decoded)&&is_object($decoded)&&isset($decoded->user_id)){
                $auth=true;
            }
            $return=$auth;
        }
        return $return;
     }
}