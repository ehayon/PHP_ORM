<?php
/**
 * User: ethanhayon
 * Date: 10/27/12
 * Time: 12:11 AM
 */

include("./lib/db/mysql.php");
include("./lib/model.php");

$db = new Database();
$db->setup("localhost", "root", "", "ParkingManagementSystem");

Model::setDB($db);


class ParkingLot extends Model {
    protected static $table_name = "parkinglots"; // we can override the table name for
}
class Comment extends Model {
    protected static $table_name = "comments"; // we can override the table name for
}



//$lot = ParkingLot::findOne("status='1'", "", "");
//ParkingLot::has_one(new Comment);
ParkingLot::has_many_through(new Comment, 'parkinglots_comments');
/* // Create a new lot
$lot = new ParkingLot();
$lot->location = "test lot";
$lot->status = 1;
$lot->available = 200;
$lot->capacity = 1000;
$lot->save();
*/
/* // create a new comment for testing
$comment = new Comment();
$comment->comment = "This lot also sucks";
$comment->save();
*/
$comment1 = Comment::findOne(array("id_comment"=>1), null);
$comment2 = Comment::findOne(array("id_comment"=>2), null);

$lot = ParkingLot::findOne(array("id_parkinglot"=>1), null);
$lot->comments = array($comment1,$comment2, null);
$lot->save();

print $lot->comments[1]->comment;


/*
$lot = ParkingLot::findOne(array("id_parkinglot"=>1), null);
if(isset($lot)) {
    $lot->location = "test lot location";
    $lot->comment = $comment1;
    $lot->save();
    print '<br />$lot->comment = '.$lot->id_comment.'<br />';
    print "<br /><br />GET Comment for lot: ";
    var_dump($lot->comment);
}
 */
