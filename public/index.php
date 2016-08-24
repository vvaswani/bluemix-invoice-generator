<?php
// use Composer autoloader
require '../vendor/autoload.php';

// load configuration
require '../config.php';

// load classes
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints as Assert;
use Silex\Application;

// initialize Silex application
$app = new Application();

// turn on application debugging
// set to false for production environments
$app['debug'] = true;

// load configuration from file
$app->config = $config;

// register Twig template provider
$app->register(new Silex\Provider\TwigServiceProvider(), array(
  'twig.path' => __DIR__.'/../views',
));

// register validator service provider
$app->register(new Silex\Provider\ValidatorServiceProvider());

// register session service provider
$app->register(new Silex\Provider\SessionServiceProvider());

// if BlueMix VCAP_SERVICES environment available
// overwrite local credentials with BlueMix credentials
if ($services = getenv("VCAP_SERVICES")) {
  $services_json = json_decode($services, true);
  $app->config['settings']['db']['hostname'] = $services_json['cleardb'][0]['credentials']['hostname'];
  $app->config['settings']['db']['username'] = $services_json['cleardb'][0]['credentials']['username'];
  $app->config['settings']['db']['password'] = $services_json['cleardb'][0]['credentials']['password'];
  $app->config['settings']['db']['name'] = $services_json['cleardb'][0]['credentials']['name'];
  $app->config['settings']['object-storage']['url'] = $services_json["Object-Storage"][0]["credentials"]["auth_url"] . '/v3';
  $app->config['settings']['object-storage']['region'] = $services_json["Object-Storage"][0]["credentials"]["region"];
  $app->config['settings']['object-storage']['user'] = $services_json["Object-Storage"][0]["credentials"]["userId"];
  $app->config['settings']['object-storage']['pass'] = $services_json["Object-Storage"][0]["credentials"]["password"];  
} 

// initialize PDF engine
$mpdf = new mPDF();

// initialize database connection
$db = new mysqli(
  $app->config['settings']['db']['hostname'], 
  $app->config['settings']['db']['username'], 
  $app->config['settings']['db']['password'], 
  $app->config['settings']['db']['name']
);

if ($db->connect_errno) {
  throw new Exception('Failed to connect to MySQL: ' . $db->connect_error);
}

// initialize OpenStack client
$openstack = new OpenStack\OpenStack(array(
  'authUrl' => $app->config['settings']['object-storage']['url'],
  'region'  => $app->config['settings']['object-storage']['region'],
  'user'    => array(
    'id'       => $app->config['settings']['object-storage']['user'],
    'password' => $app->config['settings']['object-storage']['pass']
)));
$objectstore = $openstack->objectStoreV1();

// initialize SendGrid client
$sg = new \SendGrid($app->config['settings']['sendgrid']['key']);

// index page handlers
$app->get('/', function () use ($app) {
  return $app->redirect($app["url_generator"]->generate('index'));
});

// invoice list display handler
$app->get('/index', function () use ($app, $db) {
  $result = $db->query("SELECT * FROM invoices ORDER BY ts DESC");
  $data = $result->fetch_all(MYSQLI_ASSOC);
  return $app['twig']->render('index.twig', array('data' => $data));
})->bind('index');

// invoice form display handler
$app->get('/create', function () use ($app) {
  return $app['twig']->render('create.twig');
})->bind('create');

// invoice generator
$app->post('/create', function (Request $request) use ($app, $db, $mpdf, $objectstore) {
  // collect input parameters
  $params = array(
    'name' => strip_tags(trim(strtolower($request->get('name')))),
    'address1' => strip_tags(trim($request->get('address1'))),
    'address2' => strip_tags(trim($request->get('address2'))),
    'city' => strip_tags(trim($request->get('city'))),
    'state' => strip_tags(trim($request->get('state'))),
    'postcode' => strip_tags(trim($request->get('postcode'))),
    'email' => strip_tags(trim($request->get('email'))),
    'lines' => $request->get('lines'),
  );
  
  // define validation constraints
  $constraints = new Assert\Collection(array(
    'name' => new Assert\NotBlank(array('groups' => 'invoice')),
    'address1' => new Assert\NotBlank(array('groups' => 'invoice')),
    'address2' => new Assert\Type(array('type' => 'string', 'groups' => 'invoice')),
    'city' => new Assert\NotBlank(array('groups' => 'invoice')),
    'state' => new Assert\NotBlank(array('groups' => 'invoice')),
    'postcode' => new Assert\NotBlank(array('groups' => 'invoice')),
    'email' =>  new Assert\Email(array('groups' => 'invoice')),
    'lines' =>  new App\Validator\Constraints\Lines(array('groups' => 'invoice')),
  ));
  
  // validate input and set errors if any as flash messages
  // if errors, redirect to input form
  $errors = $app['validator']->validate($params, $constraints, array('invoice'));
  if (count($errors) > 0) {
    foreach ($errors as $error) {
      $app['session']->getFlashBag()->add('error', 'Invalid input in field ' . $error->getPropertyPath() . ': ' . $error->getMessage());
    }
    return $app->redirect($app["url_generator"]->generate('create'));
  }  
  
  // if input passes validation
  // calculate subtotals and total
  $total = 0;
  foreach ($params['lines'] as $lineNum => &$lineData) {
    $lineData['subtotal'] = $lineData['qty'] * $lineData['rate'];
    $total += $lineData['subtotal'];
  }
  
  // save record to MySQL
  // get record id
  if (!$db->query("INSERT INTO invoices (name, email, amount, ts) VALUES ('" . $params['name'] . "', '" . $params['email'] . "', '" . $total . "', NOW())")) {
    $app['session']->getFlashBag()->add('Failed to save invoice to database: ' . $db->error);
    return $app->redirect($app["url_generator"]->generate('index'));
  }
  $id = $db->insert_id;
  
  // generate PDF invoice from template
  $html = $app['twig']->render('invoice.twig', array('data' => $params, 'total' => $total));
  $mpdf->WriteHTML($html);
  $pdf = $mpdf->Output('', 'S'); 

  // save PDF to container with id as name
  $container = $objectstore->getContainer('invoices');
  $options = array(
    'name'   => "$id.pdf",
    'content' => $pdf,
  );
  $container->createObject($options);
  
  // display success message
  $app['session']->getFlashBag()->add('success', "Invoice #$id created.");
  return $app->redirect($app["url_generator"]->generate('index'));
});

// invoice deletion request handler
$app->get('/delete/{id}', function ($id) use ($app, $db, $objectstore) {
  // delete invoice from database
  if (!$db->query("DELETE FROM invoices WHERE id = '$id'")) {
    $app['session']->getFlashBag()->add('Failed to delete invoice from database: ' . $db->error);
    return $app->redirect($app["url_generator"]->generate('index'));
  }
  // delete invoice from object storae
  $container = $objectstore->getContainer('invoices');
  $object = $container->getObject("$id.pdf");
  $object->delete();  
  $app['session']->getFlashBag()->add('success', "Invoice #$id deleted.");
  return $app->redirect($app["url_generator"]->generate('index'));  
})->bind('delete');

// invoice download request handler
$app->get('/download/{id}', function ($id) use ($app, $objectstore) {
  // retrieve invoice file
  $file = $objectstore->getContainer('invoices')
                      ->getObject("$id.pdf")
                      ->download();
  // set response headers and body
  // send file to client
  $response = new Response();
  $response->headers->set('Content-Type', 'application/pdf');
  $response->headers->set('Content-Disposition', 'attachment; filename="' . $id .'.pdf"');
  $response->headers->set('Content-Length', $file->getSize());
  $response->headers->set('Expires', '@0');
  $response->headers->set('Cache-Control', 'must-revalidate');
  $response->headers->set('Pragma', 'public');
  $response->setContent($file);
  return $response;
})->bind('download');

// invoice delivery request handler
$app->get('/send/{id}', function ($id) use ($app, $objectstore, $db, $sg) {
  // retrieve invoice file
  $file = $objectstore->getContainer('invoices')
                      ->getObject("$id.pdf")
                      ->download();
  $from = new SendGrid\Email(null, "no-reply@example.com");
  $subject = "Invoice #$id";
  $result = $db->query("SELECT email FROM invoices WHERE id = '$id'");
  $row = $result->fetch_assoc();
  $to = new SendGrid\Email(null, $row['email']);
  $content = new SendGrid\Content(
    "text/plain", 
    "Please note that the attached sample invoice is generated for demonstration purposes only and no payment is required.");
  $mail = new SendGrid\Mail($from, $subject, $to, $content);
  $attachment = new SendGrid\Attachment();
  $attachment->setContent(base64_encode($file));
  $attachment->setType("application/pdf");
  $attachment->setFilename("invoice_$id.pdf");
  $attachment->setDisposition("attachment");
  $attachment->setContentId("invoice_$id");
  $mail->addAttachment($attachment);
  $response = $sg->client->mail()->send()->post($mail);
  if ($response->statusCode() == 200 || $response->statusCode() == 202) {
    $app['session']->getFlashBag()->add('success', "Invoice #$id sent.");  
  } else {
    $app['session']->getFlashBag()->add('error', "Failed to send invoice.");    
  }
  return $app->redirect($app["url_generator"]->generate('index'));  
})->bind('send');

// legal page display handler
$app->get('/legal', function () use ($app) {
  return $app['twig']->render('legal.twig', array());
})->bind('legal');

// reset handler
$app->get('/reset-system', function (Request $request) use ($app, $db, $objectstore) {

  // delete and recreate database table
  try {
    if (!$db->query("DROP TABLE IF EXISTS invoices")) {
      throw new Exception('Failed to drop table: ' . $db->error);
    } 
    if (!$db->query("CREATE TABLE invoices ( id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, ts TIMESTAMP NOT NULL, name TEXT NOT NULL, email VARCHAR(255) NOT NULL, amount FLOAT NOT NULL )")) {
      throw new Exception('Failed to create table: ' . $db->error);  
    }
    $app['session']->getFlashBag()->add('success', 'Schema reset.');
  } catch (Exception $e) {
    $app['session']->getFlashBag()->add('error', 'Schema reset failure.');  
  }
  
  // delete and recreate object storage container
  // iterate over container and delete all objects
  // once empty, delete container
  try {
    if ($objectstore->containerExists('invoices')) {
      $container = $objectstore->getContainer('invoices');
      foreach ($container->listObjects() as $object) {
        $object = $container->getObject($object->name);
        $object->delete();
      }
      $container->delete();
    }
    $container = $objectstore->createContainer(array(
      'name' => 'invoices'
    ));
    $app['session']->getFlashBag()->add('success', 'Container reset.');
  } catch (Exception $e) {
    $app['session']->getFlashBag()->add('error', 'Container reset failure.');  
  }  
  return $app->redirect($app["url_generator"]->generate('index'));  
})->bind('reset-system');

// run application
$app->run();
