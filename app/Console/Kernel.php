<?php

namespace App\Console;

use App\Order;
use App\Queue_driver;
use App\Queue_order;
use App\Suggestion_price;
use App\User;
use App\UserMessage;
use App\Wallet;
use App\Api_token;
use App\Http\Controllers\SmsIR_UltraFastSend;
use Hekmatinasser\Verta\Verta;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
include_once(app_path() . '/functions/functions.php');

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
//        Commands\FindDriver::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('Discharge')->everyMinute();

        $schedule->call(function () {
            $date = date('Y-m-j H:i:s');
            $queue_rows = Queue_order::leftjoin('orders','orders.id','=','queue_orders.order_id')->where(['queue_orders.status'=>1,'orders.status_id'=>2])->where('orders.sending_date','>=',date("Y-m-d"))->get();
            foreach ($queue_rows as $queue_row){
                $count_queue=Queue_driver::where(['order_id'=>$queue_row->order_id,'status'=>1])->count();
                if ($count_queue==10 or ($count_queue>0 and strtotime($queue_row->end_time) < strtotime($date))){
                    $creator_id=Order::find($queue_row->order_id);
                    $this_creator=$creator_id->creator_id;
                    $saver_id=$creator_id->saver_employee_id;
                    if ($creator_id->creator_price!==null){
                        $creator_price=$creator_id->creator_price;
                    }else{ $creator_price=0; }
                    $min_price=Queue_driver::where(['order_id'=>$queue_row->order_id,'status'=>1])->min('price'); //کمترین قیمت
                    $min_score=User::where(['group_id'=>3])->min('score'); //کمترین امتیاز
                    $rows = Queue_driver::leftjoin('users','users.id','=','queue_drivers.driver_id')->where(['order_id'=>$queue_row->order_id,'price'=>$min_price,'queue_drivers.status'=>1])->orderBy('queue_drivers.id','desc')->get(); // رانندکانی ک کمترین قیمت رو پیشنهاد دادن
                    if(count($rows)==1) {
                        foreach ($rows as $row) {
                            if ($min_price <= $creator_price) {
                                //تعیین قطعی راننده
                                $order_info = Order::find($row->order_id);
                                $score=User::where('id',$order_info->creator_id)->first();
                                $special=$score->special;
                                $score=$score->score;//امتیاز کاربر
                                //انتخاب باربری
                                $company_row = Order::leftjoin('users', 'users.id', '=', 'orders.creator_id')->where('orders.id', $row->order_id)->first();
                                if ($company_row->group_id == 5 or $company_row->group_id == 6) {
                                    $company_id = $company_row->related_code;
                                } else {
                                    $row_referred = User::leftjoin('user_addresses', 'user_id', '=', 'users.id')->where(['user_addresses.city_id' => $order_info->start_city_id, 'users.group_id' => 5])->first();
                                    if ($row_referred) {
                                        $company_id = $row_referred->related_code;
                                    } else {
                                        $row_referred = User::leftjoin('user_addresses', 'user_id', '=', 'users.id')->leftjoin('cities', 'cities.id', '=', 'user_addresses.city_id')->where(['user_addresses.province_id' => $order_info->start_province_id, 'users.group_id' => 5, 'cities.center' => 1])->first();
                                        if ($row_referred) {
                                            $company_id = $row_referred->related_code;
                                        } else {
                                            $company_id = null;
                                        }
                                    }
                                }

                                if ($score<50 and $special==0){
                                    $deposit = 0.3 * $min_price;//بیعانه
                                    if ($deposit<2000000){
                                        $deposit=2000000;
                                    }elseif ($deposit>4000000){
                                        $deposit=4000000;
                                    }else{
                                        $deposit=round($deposit);
                                    }

                                    //آپدیت سطر سفارش در جدول order
                                    Order::where('id', $row->order_id)->update([
                                        'driver_id' => $row->driver_id,
                                        'status_id' => 3,
                                        'price_acceptor' => $row->driver_id,
                                        'deposit' => $deposit,
                                        'price' => $min_price,
                                        'company_id' => $company_id
                                    ]);
                                    //افزودن سطر بیعانه به کیف پول
                                    $creator_id = $order_info->creator_id;
                                    $wallet_info = Wallet::where('user_id', $creator_id)->get()->last();
                                    $cash = $wallet_info->cash;
                                    $cash = $cash - $deposit;
                                    $data = new Wallet();
                                    $data->order_id = $row->order_id;
                                    $data->type_id = 0;
                                    $data->reason_id = 3;
                                    $data->user_id = $creator_id;
                                    $data->status = 2;
                                    $data->price = $deposit;
                                    $data->cash = $cash;
                                    $data->save();
                                }
                                else{
                                    //آپدیت سطر سفارش در جدول order
                                    Order::where('id', $row->order_id)->update([
                                        'driver_id' => $row->driver_id,
                                        'status_id' => 3,
                                        'price_acceptor' => $row->driver_id,
                                        'price' => $min_price,
                                        'company_id' => $company_id
                                    ]);
                                }
                                //افزایش امتیاز صاحب بار
                                $order_count = Order::where('creator_id', $this_creator)->whereNotIn('status_id',[1,2])->where('id','!=',$row->order_id)->count();
                                if ($order_count == 0) {
                                    $add_score = 10;
                                } else {
                                    $add_score = 5;
                                }
                                $score = User::where('id',$this_creator)->first();
                                $score = $score->score;
                                $score = $score + $add_score;
                                User::where('id',$this_creator)->update([
                                    'score' => $score
                                ]);
                                //افزودن سطر پرداخت کارمزد به کیف پول راننده
                                $wage = 0.1 * $min_price;
                                $wage=round($wage);
                                $driver_wallet_info = Wallet::where('user_id', $row->driver_id)->get()->last();
                                $cash = $driver_wallet_info->cash;
                                $cash = $cash - $wage;
                                $data = new Wallet();
                                $data->order_id = $row->order_id;
                                $data->type_id = 0;
                                $data->reason_id = 7;
                                $data->user_id = $row->driver_id;
                                $data->status = 1;
                                $data->price = $wage;
                                $data->cash = $cash;
                                $data->save();
                                //حذف سطر سفارش از صف سفارش
                                Queue_order::where('order_id',$row->order_id)->delete();
                                $driver_id = $row->driver_id;
                                Suggestion_price::where('user_id',$driver_id)->where('order_id', $row->order_id)
                                    ->update([
                                        'status'=>5,
                                        'suggestion_driver'=>0
                                    ]);
                                Queue_driver::where('driver_id',$driver_id)->where('order_id', $row->order_id)->update([
                                    'status'=>5
                                ]);
                                //لغو پیشنهاد بقیه رانندگان
                                Suggestion_price::where('user_id','!=',$driver_id)->where('order_id', $row->order_id)
                                    ->update([
                                        'status'=>2,
                                        'suggestion_driver'=>0
                                    ]);
                                Queue_driver::where('driver_id','!=',$driver_id)->where('order_id', $row->order_id)->update([
                                    'status'=>2
                                ]);
                                //لغو پیشنهاد این راننده برای سفارشاتی که در همین روز هستند
                                //زمان فراغت از بار قطعی
                                $sending_date=$order_info->sending_date;//تاریخ بارگیری این باری که براش قطعی شده
                                $sending_time=$order_info->sending_time_from;
                                $select_order = $sending_date.' '.$sending_time;
                                $distance=$order_info->start_end_distance;
                                $dis_time = explode(".", $distance/100);
                                $hour=$dis_time[0];//مدت زمان سفر
                                if ($hour<5){$h=5;}elseif(5<=$hour and $hour<=12){$h=24;}else{$h=48;} //زمان لازم برای بنزین و تعمیر و استراحت به ازای مدت زمان سفر
                                $finish_time = strtotime('+' .$h. 'hour', strtotime($select_order));//زمان اتمام سفری ک قبلا قطعی شده و امادگی برای سفر بعدی
                                $finish_time = date ( 'Y-m-j H:i:s', $finish_time );
                                //
                                //زمان رسیدن از مقصد قطعی به مبدا بارجدید
                                $receive_date=$order_info->receive_date; //تاریخ تخلیه قطعی
                                $receive_time=$order_info->receive_time_from;//ساعت تخلیه
                                if ($receive_time==""){
                                    $receive_time='12:00';
                                }
                                $receive_date_time=$receive_date.' '.$receive_time;//ترکیب ساعت و تاریخ تخلیه
                                //فاصله مقصد تا مبدا جدید
                                $datas = Order::leftjoin('queue_drivers','queue_drivers.order_id','=','orders.id')->where('queue_drivers.driver_id',$driver_id)
                                    ->where(['orders.status_id'=>2,'queue_drivers.status'=>1])->where('sending_date','>=',date("Y-m-d"))->get(); //درخواست های باز راننده
                                foreach ($datas as $data){
                                    $start_end_distance=haversineGreatCircleDistance($order_info->end_lat,$order_info->end_lng,$data->start_lat,$data->start_lng);
                                    if (is_float($start_end_distance)){
                                        $dis_time2 = explode(".", $start_end_distance/100);
                                        $hour2=$dis_time2[0]; //مدت زمان رسیدن به مبدا جدید
                                    }else{
                                        $dis_time2 = $start_end_distance/100;
                                        $hour2=$dis_time2;
                                    }
                                    if ($hour2<5){$h2=5;}elseif(5<=$hour2 and $hour2<=12){$h2=24;}else{$h2=48;} //زمان لازم برای بنزین و تعمیر و استراحت به ازای مدت زمان سفر
                                    $recieve_to_new_start_time = strtotime('+' .$h2. 'hour', strtotime($receive_date_time));//زمان رسیدن از مقصد قبلی به مبدا جدید
                                    $recieve_to_new_start_time = date ( 'Y-m-j H:i:s', $recieve_to_new_start_time );
                                    Queue_driver::leftjoin('orders','orders.id','=','queue_drivers.order_id')->where('queue_drivers.driver_id',$driver_id)->where('orders.id',$data->order_id)
                                        ->Where(function ($query) use ($recieve_to_new_start_time,$finish_time,$receive_date_time){
                                            $query->Where(function ($query) use ($recieve_to_new_start_time,$finish_time) {
                                                $query->where('sending_date','<=',$recieve_to_new_start_time)
                                                    ->Where('sending_date','>=',$finish_time);
                                            })->orWhere(function ($query) use ($receive_date_time,$recieve_to_new_start_time) {
                                                $query->where('sending_date','<=',$recieve_to_new_start_time)
                                                    ->Where('sending_date','>=',$receive_date_time);
                                            });
                                        })->update([
                                            'status'=>3
                                        ]);
                                    Suggestion_price::leftjoin('orders','orders.id','=','suggestion_prices.order_id')->where('suggestion_prices.user_id',$driver_id)->where('orders.id',$data->order_id)
                                        ->Where(function ($query) use ($recieve_to_new_start_time,$finish_time,$receive_date_time){
                                            $query->Where(function ($query) use ($recieve_to_new_start_time,$finish_time) {
                                                $query->where('sending_date','<=',$recieve_to_new_start_time)
                                                    ->Where('sending_date','>=',$finish_time);
                                            })->orWhere(function ($query) use ($receive_date_time,$recieve_to_new_start_time) {
                                                $query->where('sending_date','<=',$recieve_to_new_start_time)
                                                    ->Where('sending_date','>=',$receive_date_time);
                                            });
                                        })->update([
                                            'status'=>3,
                                            'suggestion_driver'=>0
                                        ]);
                                }
                                //مسافت و زمان رسیدن از مقصد درخواست باز به مبدا قطعی
                                foreach ($datas as $data){
                                    $receive_date_new=$data->receive_date; //تاریخ تخلیه جدید
                                    $receive_time_new=$data->receive_time_from;//ساعت تخلیه
                                    if ($receive_time_new==""){
                                        $receive_time_new='12:00';
                                    }
                                    $receive_date_time_new=$receive_date_new.' '.$receive_time_new;//ترکیب ساعت و تاریخ تخلیه
                                    //زمان فراغت از بار جدید
                                    $sending_date_new=$data->sending_date;//تاریخ بارگیری این باری که درخواست داده براش
                                    $sending_time_new=$data->sending_time_from;
                                    $select_new = $sending_date_new.' '.$sending_time_new;
                                    $distance=$data->start_end_distance;
                                    $dis_time = explode(".", $distance/100);
                                    $hour=$dis_time[0];//مدت زمان سفر
                                    if ($hour<5){$h=5;}elseif(5<=$hour and $hour<=12){$h=24;}else{$h=48;} //زمان لازم برای بنزین و تعمیر و استراحت به ازای مدت زمان سفر
                                    $finish_new = strtotime('+' .$h. 'hour', strtotime($select_new));//زمان اتمام سفر جدید و امادگی برای سفر بعدی
                                    $finish_new = date ( 'Y-m-j H:i:s', $finish_new );
                                    //فاصله مقصد تا مبدا جدید
                                    $start_end_distance=haversineGreatCircleDistance($data->end_lat,$data->end_lng,$order_info->start_lat,$order_info->start_lng);
                                    if (is_float($start_end_distance)){
                                        $dis_time2 = explode(".", $start_end_distance/100);
                                        $hour2=$dis_time2[0]; //مدت زمان رسیدن به مبدا جدید
                                    }else{
                                        $dis_time2 = $start_end_distance/100;
                                        $hour2=$dis_time2;
                                    }
                                    if ($hour2<5){$h2=5;}elseif(5<=$hour2 and $hour2<=12){$h2=24;}else{$h2=48;} //زمان لازم برای بنزین و تعمیر و استراحت به ازای مدت زمان سفر
                                    $recieve_to_new_start_time = strtotime('+' .$h2. 'hour', strtotime($receive_date_time_new));//زمان رسیدن از مقصد جدید به مبدا قطعی
                                    $recieve_to_new_start_time = date ( 'Y-m-j H:i:s', $recieve_to_new_start_time );
                                    if ((strtotime($receive_date_time_new)<=strtotime($select_order) and strtotime($select_order)<=strtotime($recieve_to_new_start_time)) or (strtotime($finish_new)<=strtotime($select_order) and strtotime($select_order)<=strtotime($recieve_to_new_start_time))) {
                                        Queue_driver::leftjoin('orders', 'orders.id', '=', 'queue_drivers.order_id')->where('queue_drivers.driver_id', $driver_id)->where('orders.id', $data->order_id)
                                            ->update([
                                                'status' => 3
                                            ]);
                                        Suggestion_price::leftjoin('orders', 'orders.id', '=', 'suggestion_prices.order_id')->where('suggestion_prices.user_id', $driver_id)->where('orders.id', $data->order_id)
                                            ->update([
                                                'status' => 3,
                                                'suggestion_driver' => 0
                                            ]);
                                    }
                                }

                                Queue_driver::leftjoin('orders','orders.id','=','queue_drivers.order_id')->where('queue_drivers.driver_id',$driver_id)
                                    ->where(['orders.status_id'=>2,'queue_drivers.status'=>1])->where('sending_date','>=',date("Y-m-d"))
                                    ->Where(function ($query) use ($sending_date,$select_order,$receive_date,$finish_time){
                                        $query->Where(function ($query) use ($sending_date,$receive_date) {
                                            $query->where('sending_date', '<=', $receive_date)
                                                ->Where('sending_date', '>=', $sending_date);
                                        })->orWhere(function ($query) use ($select_order,$finish_time) {
                                            $query->where('sending_date','<=',$finish_time)
                                                ->Where('sending_date','>=',$select_order);
                                        });
                                    })->update([
                                        'status'=>3
                                    ]);
                                Suggestion_price::leftjoin('orders','orders.id','=','suggestion_prices.order_id')->where('suggestion_prices.user_id',$driver_id)
                                    ->where(['orders.status_id'=>2,'suggestion_prices.status'=>1])->where('sending_date','>=',date("Y-m-d"))
                                    ->Where(function ($query) use ($sending_date,$select_order,$receive_date,$finish_time){
                                        $query->Where(function ($query) use ($sending_date,$receive_date) {
                                            $query->where('sending_date', '<=', $receive_date)
                                                ->Where('sending_date', '>=', $sending_date);
                                        })->orWhere(function ($query) use ($select_order,$finish_time) {
                                            $query->where('sending_date','<=',$finish_time)
                                                ->Where('sending_date','>=',$select_order);
                                        });
                                    })->update([
                                        'status'=>3,
                                        'suggestion_driver'=>0
                                    ]);
                                $rows=Api_token::select('user_id','user_api_token')->where('user_id',$driver_id)->get();
                                $title="دریافت بار";
                                $row_order=Order::leftjoin('users as driver', 'driver.id', '=', 'orders.driver_id')->leftjoin('bar_types', 'bar_types.id', '=', 'orders.type_id')->leftJoin('cities as destination_city', 'destination_city.id','=','orders.destination_city_id')->leftJoin('cities as start_city', 'start_city.id','=','orders.start_city_id')->select('*','start_city.city_title as start_city_title','destination_city.city_title as destination_city_title','driver.mobile_number as mobile')->where('orders.id',$queue_row->order_id)->first();
                                if ($row_order->type_desc!=""){
                                    $bar_type=$row_order->type_desc;
                                }
                                else{
                                    $bar_type=$row_order->bar_type_title;
                                }
                                $send_date = new Verta($row_order->sending_date);
                                $body="بار " .$bar_type ." از مبدا " .$row_order->start_city_title ." به ".$row_order->destination_city_title ." برای تاریخ ".str_replace("-","/",$send_date->formatDate()). " برای شما درنظرگرفته شد. مشاهده جزییات بیشتر در پنل کاربری، بخش سفرهای پیش رو";
                                foreach ($rows->unique('user_api_token') as $row){
                                    $fcm_token=$row->user_api_token;
                                    push_notification($fcm_token , $body , $title);
                                }
                                $save_date=new Verta();
                                $data = new UserMessage();
                                $data->order_id = $queue_row->order_id;
                                $data->user_id = $driver_id;
                                $data->driver_id = $driver_id;
                                $data->title = $title;
                                $data->content = $body;
                                $data->type = 2;
                                $data->save_date = str_replace("-","/",$save_date->formatDate());
                                $data->save();
                                $data = array(
                                    "ParameterArray" => array(
                                        array(
                                            "Parameter" => "barType",
                                            "ParameterValue" => $bar_type
                                        ),
                                        array(
                                            "Parameter" => "startCityTitle",
                                            "ParameterValue" => $row_order->start_city_title
                                        ),
                                        array(
                                            "Parameter" => "destinationCityTitle",
                                            "ParameterValue" => $row_order->destination_city_title
                                        ),
                                        array(
                                            "Parameter" => "sendDate",
                                            "ParameterValue" => str_replace("-","/",$send_date->formatDate())
                                        ),
                                    ),
                                    "Mobile" => $row_order->mobile,
                                    "TemplateId" => "46654"
                                );
                                $SmsIR_UltraFastSend = new SmsIR_UltraFastSend();
                                $SmsIR_UltraFastSend->ultraFastSend($data);

                                $rows=Api_token::select('user_id','user_api_token')->where('user_id',$saver_id)->get();
                                $title="تعیین راننده بار";
                                $row_order=Order::leftjoin('users as creator', 'creator.id', '=', 'orders.saver_employee_id')->leftjoin('bar_types', 'bar_types.id', '=', 'orders.type_id')->leftJoin('cities as destination_city', 'destination_city.id','=','orders.destination_city_id')->leftJoin('cities as start_city', 'start_city.id','=','orders.start_city_id')->select('*','start_city.city_title as start_city_title','destination_city.city_title as destination_city_title','creator.mobile_number as mobile')->where('orders.id',$queue_row->order_id)->first();
                                if ($row_order->type_desc!=""){
                                    $bar_type=$row_order->type_desc;
                                }
                                else{
                                    $bar_type=$row_order->bar_type_title;
                                }
                                $body="راننده مناسب برای بار ".$bar_type ." از مبدا " .$row_order->start_city_title ." به ".$row_order->destination_city_title ." با شماره پیگیری ".$row_order->tracking_code. " برای شما تعیین شد. مشاهده جزییات بیشتر در پنل کاربری، بخش سفارشات باز";
                                foreach ($rows->unique('user_api_token') as $row){
                                    $fcm_token=$row->user_api_token;
                                    push_notification($fcm_token , $body , $title);
                                }
                                $data = new UserMessage();
                                $data->order_id = $queue_row->order_id;
                                $data->user_id = $saver_id;
                                $data->driver_id = $driver_id;
                                $data->title = $title;
                                $data->content = $body;
                                $data->type = 2;
                                $data->save_date = str_replace("-","/",$save_date->formatDate());
                                $data->save();

                                $data = array(
                                    "ParameterArray" => array(
                                        array(
                                            "Parameter" => "barType",
                                            "ParameterValue" => $bar_type
                                        ),
                                        array(
                                            "Parameter" => "startCityTitle",
                                            "ParameterValue" => $row_order->start_city_title
                                        ),
                                        array(
                                            "Parameter" => "destinationCityTitle",
                                            "ParameterValue" => $row_order->destination_city_title
                                        ),
                                        array(
                                            "Parameter" => "trackingCode",
                                            "ParameterValue" => $row_order->tracking_code
                                        ),
                                    ),
                                    "Mobile" => $row_order->mobile,
                                    "TemplateId" => "46649"
                                );
                                $SmsIR_UltraFastSend = new SmsIR_UltraFastSend();
                                $SmsIR_UltraFastSend->ultraFastSend($data);

                                //ارسال پیام معرفی باربری به راننده
                                $rows=Api_token::select('user_id','user_api_token')->where('user_id',$driver_id)->get();
                                $title="معرفی باربری";
                                $rowOrderSms=Order::leftjoin('bar_types', 'bar_types.id', '=', 'orders.type_id')->select('*','bar_types.bar_type_title as bar_type_title')->where('orders.id',$queue_row->order_id)->first();
                                if ($rowOrderSms->type_desc!=""){
                                    $bar_type=$rowOrderSms->type_desc;
                                }
                                else{
                                    $bar_type=$rowOrderSms->bar_type_title;
                                }
                                if ($company_id==null){
                                    $company_name="";
                                    $address="";
                                    $phone="";
                                }else{
                                    $rowCompanySms=User::where('group_id',5)->where('related_code',$company_id)->first();
                                    $company_name=$rowCompanySms->company_name;
                                    $phone=$rowCompanySms->phone_number;
                                    $address=$rowCompanySms->address;
                                }
                                $body="راننده محترم برای دریافت حواله و بارنامه بار ".$bar_type." با کد پیگیری ".$rowOrderSms->tracking_code." به ".$company_name." مراجعه نمایید. آدرس باربری: ".$address." / شماره تماس: ".$phone." / برای مشاهده اطلاعات باربری می توانید به بخش سفرهای پیش رو، جزییات مراجعه نمایید.";
                                foreach ($rows->unique('user_api_token') as $row){
                                    $fcm_token=$row->user_api_token;
                                    push_notification($fcm_token , $body , $title);
                                }
                                $save_date=new Verta();
                                $data = new UserMessage();
                                $data->order_id = $queue_row->order_id;
                                $data->user_id = $driver_id;
                                $data->driver_id =$driver_id;
                                $data->title = $title;
                                $data->content = $body;
                                $data->type = 2;
                                $data->save_date = str_replace("-","/",$save_date->formatDate());
                                $data->save();
                            }
                            else {
                                //پیشنهاد راننده به صاحب بار
                                Queue_order::where('order_id' ,$row->order_id)
                                    ->update([
                                        'status' => 0
                                    ]);
                                Suggestion_price::where(['order_id' => $row->order_id, 'user_id' => $row->driver_id])
                                    ->update([
                                        'suggestion_driver' => 1
                                    ]);
                                $order_info = Order::find($row->order_id);
                                $rows=Api_token::select('user_id','user_api_token')->where('user_id',$order_info->creator_id)->get();
                                $title="پیشنهاد راننده بار";
                                $row_order=Order::leftjoin('users as creator', 'creator.id', '=', 'orders.saver_employee_id')->leftjoin('bar_types', 'bar_types.id', '=', 'orders.type_id')->leftJoin('cities as destination_city', 'destination_city.id','=','orders.destination_city_id')->leftJoin('cities as start_city', 'start_city.id','=','orders.start_city_id')->select('*','start_city.city_title as start_city_title','destination_city.city_title as destination_city_title','creator.mobile_number as mobile')->where('orders.id',$queue_row->order_id)->first();
                                if ($row_order->type_desc!=""){
                                    $bar_type=$row_order->type_desc;
                                }
                                else{
                                    $bar_type=$row_order->bar_type_title;
                                }
                                $body="راننده مناسب برای بار ".$bar_type ." از مبدا " .$row_order->start_city_title ." به ".$row_order->destination_city_title ." با شماره پیگیری ".$row_order->tracking_code. " به شما پیشنهاد داده شد. مشاهده جزییات بیشتر در پنل کاربری، بخش سفارشات باز";
                                foreach ($rows->unique('user_api_token') as $row){
                                    $fcm_token=$row->user_api_token;
                                    push_notification($fcm_token , $body , $title);
                                }
                                $save_date=new Verta();
                                $data = new UserMessage();
                                $data->order_id = $row->order_id;
                                $data->user_id = $order_info->creator_id;
                                $data->driver_id = $row->driver_id;
                                $data->title = $title;
                                $data->content = $body;
                                $data->type = 1;
                                $data->save_date = str_replace("-","/",$save_date->formatDate());
                                $data->save();

                                $data = array(
                                    "ParameterArray" => array(
                                        array(
                                            "Parameter" => "barType",
                                            "ParameterValue" => $bar_type
                                        ),
                                        array(
                                            "Parameter" => "startCityTitle",
                                            "ParameterValue" => $row_order->start_city_title
                                        ),
                                        array(
                                            "Parameter" => "destinationCityTitle",
                                            "ParameterValue" => $row_order->destination_city_title
                                        ),
                                        array(
                                            "Parameter" => "trackingCode",
                                            "ParameterValue" => $row_order->tracking_code
                                        ),
                                    ),
                                    "Mobile" => $row_order->mobile,
                                    "TemplateId" => "46650"
                                );
                                $SmsIR_UltraFastSend = new SmsIR_UltraFastSend();
                                $SmsIR_UltraFastSend->ultraFastSend($data);
                            }
                        }
                    }
                    else{
                        //انتخاب بهترین راننده
                        $score=$min_score;
                        $driver_id="";
                        foreach ($rows as $row) {
                            if ($score <= $row->score) {
                                $score = $row->score;
                                $driver_id = $row->driver_id;
                            }
                        }
                        if ($min_price <= $creator_price){
//تعیین قطعی راننده
                            $order_info = Order::find($queue_row->order_id);
                            $score=User::where('id',$order_info->creator_id)->first();
                            $special=$score->special;
                            $score=$score->score;//امتیاز کاربر
                            //انتخاب باربری
                            $company_row = Order::leftjoin('users', 'users.id', '=', 'orders.creator_id')->where('orders.id', $queue_row->order_id)->first();
                            if ($company_row->group_id == 5 or $company_row->group_id == 6) {
                                $company_id = $company_row->related_code;
                            } else {
                                $row_referred = user::leftjoin('user_addresses', 'user_addresses.user_id', '=', 'users.id')->where(['user_addresses.city_id' => $order_info->start_city_id, 'users.group_id' => 5])->first();
                                if ($row_referred) {
                                    $company_id = $row_referred->related_code;
                                } else {
                                    $row_referred = user::leftjoin('user_addresses', 'user_addresses.user_id', '=', 'users.id')->leftjoin('cities', 'cities.id', '=', 'user_addresses.city_id')->where(['user_addresses.province_id' => $order_info->start_province_id, 'users.group_id' => 5, 'cities.center' => 1])->first();
                                    if ($row_referred) {
                                        $company_id = $row_referred->related_code;
                                    } else {
                                        $company_id = null;
                                    }
                                }
                            }

                            if ($score<50 and $special==0){
                                $deposit = 0.3 * $min_price;//بیعانه
                                if ($deposit<2000000){
                                    $deposit=2000000;
                                }elseif ($deposit>4000000){
                                    $deposit=4000000;
                                }else{
                                    $deposit=round($deposit);
                                }
                                //آپدیت سطر سفارش در جدول order
                                Order::where('id', $queue_row->order_id)->update([
                                    'driver_id' => $driver_id,
                                    'status_id' => 3,
                                    'price_acceptor' => $driver_id,
                                    'deposit' => $deposit,
                                    'price' => $min_price,
                                    'company_id' => $company_id
                                ]);
                                //افزودن سطر بیعانه به کیف پول
                                $creator_id = $order_info->creator_id;
                                $wallet_info = Wallet::where('user_id', $creator_id)->get()->last();
                                $cash = $wallet_info->cash;
                                $cash = $cash - $deposit;
                                $data = new Wallet();
                                $data->order_id = $queue_row->order_id;
                                $data->type_id = 0;
                                $data->reason_id = 3;
                                $data->user_id = $creator_id;
                                $data->status = 2;
                                $data->price = $deposit;
                                $data->cash = $cash;
                                $data->save();
                            }
                            else{
                                //آپدیت سطر سفارش در جدول order
                                Order::where('id', $queue_row->order_id)->update([
                                    'driver_id' => $driver_id,
                                    'status_id' => 3,
                                    'price_acceptor' => $driver_id,
                                    'price' => $min_price,
                                    'company_id' => $company_id
                                ]);
                            }
                            //افزایش امتیاز صاحب بار
                            $order_count = Order::where('creator_id', $this_creator)->whereNotIn('status_id',[1,2])->where('id','!=',$queue_row->order_id)->count();
                            if ($order_count==0){
                                $add_score=10;
                            }else{
                                $add_score=5;
                            }
                            $score=User::where('id',$this_creator)->first();
                            $score=$score->score;
                            $score =$score + $add_score;
                            User::where('id', $this_creator)->update([
                                'score'=>$score
                            ]);
                            //افزودن سطر پرداخت کارمزد به کیف پول راننده
                            $wage=0.1*$min_price;
                            $wage=round($wage);
                            $driver_wallet_info = Wallet::where('user_id',$driver_id)->get()->last();
                            $cash=$driver_wallet_info->cash;
                            $cash=$cash-$wage;
                            $data = new Wallet();
                            $data->order_id= $queue_row->order_id;
                            $data->type_id=0;
                            $data->reason_id=7;
                            $data->user_id=$driver_id;
                            $data->status=1;
                            $data->price=$wage;
                            $data->cash=$cash;
                            $data->save();
                            //حذف سطر سفارش از صف سفارش
                            Queue_order::where('order_id',$queue_row->order_id)->delete();
                            Suggestion_price::where('user_id',$driver_id)->where('order_id', $queue_row->order_id)
                                ->update([
                                    'status'=>5,
                                    'suggestion_driver'=>0
                                ]);
                            Queue_driver::where('driver_id',$driver_id)->where('order_id', $queue_row->order_id)->update([
                                'status'=>5
                            ]);
                            //لغو پیشنهاد بقیه رانندگان
                            Suggestion_price::where('user_id','!=',$driver_id)->where('order_id', $queue_row->order_id)
                                ->update([
                                    'status'=>2,
                                    'suggestion_driver'=>0
                                ]);
                            Queue_driver::where('driver_id','!=',$driver_id)->where('order_id', $queue_row->order_id)->update([
                                'status'=>2
                            ]);
                            //لغو پیشنهاد این راننده برای سفارشاتی که در همین روز هستند
                            //زمان فراغت از بار قطعی
                            $sending_date=$order_info->sending_date;//تاریخ بارگیری این باری که براش قطعی شده
                            $sending_time=$order_info->sending_time_from;
                            $select_order = $sending_date.' '.$sending_time;
                            $distance=$order_info->start_end_distance;
                            $dis_time = explode(".", $distance/100);
                            $hour=$dis_time[0];//مدت زمان سفر
                            if ($hour<5){$h=5;}elseif(5<=$hour and $hour<=12){$h=24;}else{$h=48;} //زمان لازم برای بنزین و تعمیر و استراحت به ازای مدت زمان سفر
                            $finish_time = strtotime('+' .$h. 'hour', strtotime($select_order));//زمان اتمام سفری ک قبلا قطعی شده و امادگی برای سفر بعدی
                            $finish_time = date ( 'Y-m-j H:i:s', $finish_time );
                            //
                            //زمان رسیدن از مقصد قطعی به مبدا بارجدید
                            $receive_date=$order_info->receive_date; //تاریخ تخلیه قطعی
                            $receive_time=$order_info->receive_time_from;//ساعت تخلیه
                            if ($receive_time==""){
                                $receive_time='12:00';
                            }
                            $receive_date_time=$receive_date.' '.$receive_time;//ترکیب ساعت و تاریخ تخلیه
                            //فاصله مقصد تا مبدا جدید
                            $datas = Order::leftjoin('queue_drivers','queue_drivers.order_id','=','orders.id')->where('queue_drivers.driver_id',$driver_id)
                                ->where(['orders.status_id'=>2,'queue_drivers.status'=>1])->where('sending_date','>=',date("Y-m-d"))->get(); //درخواست های باز راننده
                            foreach ($datas as $data){
                                $start_end_distance=haversineGreatCircleDistance($order_info->end_lat,$order_info->end_lng,$data->start_lat,$data->start_lng);
                                if (is_float($start_end_distance)){
                                    $dis_time2 = explode(".", $start_end_distance/100);
                                    $hour2=$dis_time2[0]; //مدت زمان رسیدن به مبدا جدید
                                }else{
                                    $dis_time2 = $start_end_distance/100;
                                    $hour2=$dis_time2;
                                }
                                if ($hour2<5){$h2=5;}elseif(5<=$hour2 and $hour2<=12){$h2=24;}else{$h2=48;} //زمان لازم برای بنزین و تعمیر و استراحت به ازای مدت زمان سفر
                                $recieve_to_new_start_time = strtotime('+' .$h2. 'hour', strtotime($receive_date_time));//زمان رسیدن از مقصد قبلی به مبدا جدید
                                $recieve_to_new_start_time = date ( 'Y-m-j H:i:s', $recieve_to_new_start_time );
                                Queue_driver::leftjoin('orders','orders.id','=','queue_drivers.order_id')->where('queue_drivers.driver_id',$driver_id)->where('orders.id',$data->order_id)
                                    ->Where(function ($query) use ($recieve_to_new_start_time,$finish_time,$receive_date_time){
                                        $query->Where(function ($query) use ($recieve_to_new_start_time,$finish_time) {
                                            $query->where('sending_date','<=',$recieve_to_new_start_time)
                                                ->Where('sending_date','>=',$finish_time);
                                        })->orWhere(function ($query) use ($receive_date_time,$recieve_to_new_start_time) {
                                            $query->where('sending_date','<=',$recieve_to_new_start_time)
                                                ->Where('sending_date','>=',$receive_date_time);
                                        });
                                    })->update([
                                        'status'=>3
                                    ]);
                                Suggestion_price::leftjoin('orders','orders.id','=','suggestion_prices.order_id')->where('suggestion_prices.user_id',$driver_id)->where('orders.id',$data->order_id)
                                    ->Where(function ($query) use ($recieve_to_new_start_time,$finish_time,$receive_date_time){
                                        $query->Where(function ($query) use ($recieve_to_new_start_time,$finish_time) {
                                            $query->where('sending_date','<=',$recieve_to_new_start_time)
                                                ->Where('sending_date','>=',$finish_time);
                                        })->orWhere(function ($query) use ($receive_date_time,$recieve_to_new_start_time) {
                                            $query->where('sending_date','<=',$recieve_to_new_start_time)
                                                ->Where('sending_date','>=',$receive_date_time);
                                        });
                                    })->update([
                                        'status'=>3,
                                        'suggestion_driver'=>0
                                    ]);
                            }
//مسافت و زمان رسیدن از مقصد درخواست باز به مبدا قطعی
                            foreach ($datas as $data){
                                $receive_date_new=$data->receive_date; //تاریخ تخلیه جدید
                                $receive_time_new=$data->receive_time;//ساعت تخلیه
                                if ($receive_time_new==""){
                                    $receive_time_new='08:00';
                                }
                                $receive_date_time_new=$receive_date_new.' '.$receive_time_new;//ترکیب ساعت و تاریخ تخلیه
                                //زمان فراغت از بار جدید
                                $sending_date_new=$data->sending_date;//تاریخ بارگیری این باری که درخواست داده براش
                                $sending_time_new=$data->sending_time_to;
                                $select_new = $sending_date_new.' '.$sending_time_new;
                                $distance=$data->start_end_distance;
                                $dis_time = explode(".", $distance/100);
                                $hour=$dis_time[0];//مدت زمان سفر
                                if ($hour<5){$h=5;}elseif(5<=$hour and $hour<=12){$h=24;}else{$h=48;} //زمان لازم برای بنزین و تعمیر و استراحت به ازای مدت زمان سفر
                                $finish_new = strtotime('+' .$h. 'hour', strtotime($select_new));//زمان اتمام سفر جدید و امادگی برای سفر بعدی
                                $finish_new = date ( 'Y-m-j H:i:s', $finish_new );
                                //فاصله مقصد تا مبدا جدید
                                $start_end_distance=haversineGreatCircleDistance($data->end_lat,$data->end_lng,$order_info->start_lat,$order_info->start_lng);
                                if (is_float($start_end_distance)){
                                    $dis_time2 = explode(".", $start_end_distance/100);
                                    $hour2=$dis_time2[0]; //مدت زمان رسیدن به مبدا جدید
                                }else{
                                    $dis_time2 = $start_end_distance/100;
                                    $hour2=$dis_time2;
                                }
                                if ($hour2<5){$h2=5;}elseif(5<=$hour2 and $hour2<=12){$h2=24;}else{$h2=48;} //زمان لازم برای بنزین و تعمیر و استراحت به ازای مدت زمان سفر
                                $recieve_to_new_start_time = strtotime('+' .$h2. 'hour', strtotime($receive_date_time_new));//زمان رسیدن از مقصد جدید به مبدا قطعی
                                $recieve_to_new_start_time = date ( 'Y-m-j H:i:s', $recieve_to_new_start_time );
                                if ((strtotime($receive_date_time_new)<=strtotime($select_order) and strtotime($select_order)<=strtotime($recieve_to_new_start_time)) or (strtotime($finish_new)<=strtotime($select_order) and strtotime($select_order)<=strtotime($recieve_to_new_start_time))) {
                                    Queue_driver::leftjoin('orders', 'orders.id', '=', 'queue_drivers.order_id')->where('queue_drivers.driver_id', $driver_id)->where('orders.id', $data->order_id)
                                        ->update([
                                            'status' => 3
                                        ]);
                                    Suggestion_price::leftjoin('orders', 'orders.id', '=', 'suggestion_prices.order_id')->where('suggestion_prices.user_id', $driver_id)->where('orders.id', $data->order_id)
                                        ->update([
                                            'status' => 3,
                                            'suggestion_driver' => 0
                                        ]);
                                }
                            }
                            Queue_driver::leftjoin('orders','orders.id','=','queue_drivers.order_id')->where('queue_drivers.driver_id',$driver_id)
                                ->where(['orders.status_id'=>2,'queue_drivers.status'=>1])->where('sending_date','>=',date("Y-m-d"))
                                ->Where(function ($query) use ($sending_date,$select_order,$receive_date,$finish_time){
                                    $query->Where(function ($query) use ($sending_date,$receive_date) {
                                        $query->where('sending_date', '<=', $receive_date)
                                            ->Where('sending_date', '>=', $sending_date);
                                    })->orWhere(function ($query) use ($select_order,$finish_time) {
                                        $query->where('sending_date','<=',$finish_time)
                                            ->Where('sending_date','>=',$select_order);
                                    });
                                })->update([
                                    'status'=>3
                                ]);
                            Suggestion_price::leftjoin('orders','orders.id','=','suggestion_prices.order_id')->where('suggestion_prices.user_id',$driver_id)
                                ->where(['orders.status_id'=>2,'suggestion_prices.status'=>1])->where('sending_date','>=',date("Y-m-d"))
                                ->Where(function ($query) use ($sending_date,$select_order,$receive_date,$finish_time){
                                    $query->Where(function ($query) use ($sending_date,$receive_date) {
                                        $query->where('sending_date', '<=', $receive_date)
                                            ->Where('sending_date', '>=', $sending_date);
                                    })->orWhere(function ($query) use ($select_order,$finish_time) {
                                        $query->where('sending_date','<=',$finish_time)
                                            ->Where('sending_date','>=',$select_order);
                                    });
                                })->update([
                                    'status'=>3,
                                    'suggestion_driver'=>0
                                ]);
                            $rows=Api_token::select('user_id','user_api_token')->where('user_id',$driver_id)->get();
                            $title="دریافت بار";
                            $row_order=Order::leftjoin('users as driver', 'driver.id', '=', 'orders.driver_id')->leftjoin('bar_types', 'bar_types.id', '=', 'orders.type_id')->leftJoin('cities as destination_city', 'destination_city.id','=','orders.destination_city_id')->leftJoin('cities as start_city', 'start_city.id','=','orders.start_city_id')->select('*','start_city.city_title as start_city_title','destination_city.city_title as destination_city_title','driver.mobile_number as mobile')->where('orders.id',$queue_row->order_id)->first();
                            if ($row_order->type_desc!=""){
                                $bar_type=$row_order->type_desc;
                            }
                            else{
                                $bar_type=$row_order->bar_type_title;
                            }
                            $send_date = new Verta($row_order->sending_date);
                            $body="بار " .$bar_type ." از مبدا " .$row_order->start_city_title ." به ".$row_order->destination_city_title ." برای تاریخ ".str_replace("-","/",$send_date->formatDate()). " برای شما درنظرگرفته شد. مشاهده جزییات بیشتر در پنل کاربری، بخش سفرهای پیش رو";
                            foreach ($rows->unique('user_api_token') as $row){
                                $fcm_token=$row->user_api_token;
                                push_notification($fcm_token , $body , $title);
                            }
                            $save_date=new Verta();
                            $data = new UserMessage();
                            $data->order_id = $queue_row->order_id;
                            $data->user_id = $driver_id;
                            $data->driver_id =$driver_id;
                            $data->title = $title;
                            $data->content = $body;
                            $data->type = 2;
                            $data->save_date = str_replace("-","/",$save_date->formatDate());
                            $data->save();

                            $data = array(
                                "ParameterArray" => array(
                                    array(
                                        "Parameter" => "barType",
                                        "ParameterValue" => $bar_type
                                    ),
                                    array(
                                        "Parameter" => "startCityTitle",
                                        "ParameterValue" => $row_order->start_city_title
                                    ),
                                    array(
                                        "Parameter" => "destinationCityTitle",
                                        "ParameterValue" => $row_order->destination_city_title
                                    ),
                                    array(
                                        "Parameter" => "sendDate",
                                        "ParameterValue" => str_replace("-","/",$send_date->formatDate())
                                    ),
                                ),
                                "Mobile" => $row_order->mobile,
                                "TemplateId" => "46654"
                            );
                            $SmsIR_UltraFastSend = new SmsIR_UltraFastSend();
                            $SmsIR_UltraFastSend->ultraFastSend($data);

                            $rows=Api_token::select('user_id','user_api_token')->where('user_id',$saver_id)->get();
                            $title="تعیین راننده بار";
                            $row_order=Order::leftjoin('users as creator', 'creator.id', '=', 'orders.saver_employee_id')->leftjoin('bar_types', 'bar_types.id', '=', 'orders.type_id')->leftJoin('cities as destination_city', 'destination_city.id','=','orders.destination_city_id')->leftJoin('cities as start_city', 'start_city.id','=','orders.start_city_id')->select('*','start_city.city_title as start_city_title','destination_city.city_title as destination_city_title','creator.mobile_number as mobile')->where('orders.id',$queue_row->order_id)->first();
                            if ($row_order->type_desc!=""){
                                $bar_type=$row_order->type_desc;
                            }
                            else{
                                $bar_type=$row_order->bar_type_title;
                            }
                            $body="راننده مناسب برای بار ".$bar_type ." از مبدا " .$row_order->start_city_title ." به ".$row_order->destination_city_title ." با شماره پیگیری ".$row_order->tracking_code. " برای شما تعیین شد. مشاهده جزییات بیشتر در پنل کاربری، بخش سفارشات باز";
                            foreach ($rows->unique('user_api_token') as $row){
                                $fcm_token=$row->user_api_token;
                                push_notification($fcm_token , $body , $title);
                            }
                            $data = new UserMessage();
                            $data->order_id = $queue_row->order_id;
                            $data->user_id = $saver_id;
                            $data->driver_id =$driver_id;
                            $data->title = $title;
                            $data->content = $body;
                            $data->type = 2;
                            $data->save_date = str_replace("-","/",$save_date->formatDate());
                            $data->save();
                            $data = array(
                                "ParameterArray" => array(
                                    array(
                                        "Parameter" => "barType",
                                        "ParameterValue" => $bar_type
                                    ),
                                    array(
                                        "Parameter" => "startCityTitle",
                                        "ParameterValue" => $row_order->start_city_title
                                    ),
                                    array(
                                        "Parameter" => "destinationCityTitle",
                                        "ParameterValue" => $row_order->destination_city_title
                                    ),
                                    array(
                                        "Parameter" => "trackingCode",
                                        "ParameterValue" => $row_order->tracking_code
                                    ),
                                ),
                                "Mobile" => $row_order->mobile,
                                "TemplateId" => "46649"
                            );
                            $SmsIR_UltraFastSend = new SmsIR_UltraFastSend();
                            $SmsIR_UltraFastSend->ultraFastSend($data);

                            //ارسال پیام معرفی باربری به راننده
                            $rows=Api_token::select('user_id','user_api_token')->where('user_id',$driver_id)->get();
                            $title="معرفی باربری";
                            $rowOrderSms=Order::leftjoin('bar_types', 'bar_types.id', '=', 'orders.type_id')->select('*','bar_types.bar_type_title as bar_type_title')->where('orders.id',$queue_row->order_id)->first();
                            if ($rowOrderSms->type_desc!=""){
                                $bar_type=$rowOrderSms->type_desc;
                            }
                            else{
                                $bar_type=$rowOrderSms->bar_type_title;
                            }
                            if ($company_id==null){
                                $company_name="";
                                $address="";
                                $phone="";
                            }else{
                                $rowCompanySms=User::where('group_id',5)->where('related_code',$company_id)->first();
                                $company_name=$rowCompanySms->company_name;
                                $phone=$rowCompanySms->phone_number;
                                $address=$rowCompanySms->address;
                            }
                            $body="راننده محترم برای دریافت حواله و بارنامه بار ".$bar_type." با کد پیگیری ".$rowOrderSms->tracking_code." به ".$company_name." مراجعه نمایید. آدرس باربری: ".$address." / شماره تماس: ".$phone." / برای مشاهده اطلاعات باربری می توانید به بخش سفرهای پیش رو، جزییات مراجعه نمایید.";
                            foreach ($rows->unique('user_api_token') as $row){
                                $fcm_token=$row->user_api_token;
                                push_notification($fcm_token , $body , $title);
                            }
                            $save_date=new Verta();
                            $data = new UserMessage();
                            $data->order_id = $queue_row->order_id;
                            $data->user_id = $driver_id;
                            $data->driver_id =$driver_id;
                            $data->title = $title;
                            $data->content = $body;
                            $data->type = 2;
                            $data->save_date = str_replace("-","/",$save_date->formatDate());
                            $data->save();
                        }
                        else{
                            //پیشنهاد راننده به صاحب بار
                            Queue_order::where('order_id' ,$queue_row->order_id)
                                ->update([
                                    'status' => 0
                                ]);
                            Suggestion_price::where(['order_id'=>$queue_row->order_id,'user_id'=>$driver_id])
                                ->update([
                                    'suggestion_driver'=>1
                                ]);
                            $order_info = Order::find($queue_row->order_id);
                            $rows=Api_token::select('user_id','user_api_token')->where('user_id',$order_info->creator_id)->get();
                            $title="پیشنهاد راننده بار";
                            $row_order=Order::leftjoin('users as creator', 'creator.id', '=', 'orders.saver_employee_id')->leftjoin('bar_types', 'bar_types.id', '=', 'orders.type_id')->leftJoin('cities as destination_city', 'destination_city.id','=','orders.destination_city_id')->leftJoin('cities as start_city', 'start_city.id','=','orders.start_city_id')->select('*','start_city.city_title as start_city_title','destination_city.city_title as destination_city_title','creator.mobile_number as mobile')->where('orders.id',$queue_row->order_id)->first();
                            if ($row_order->type_desc!=""){
                                $bar_type=$row_order->type_desc;
                            }
                            else{
                                $bar_type=$row_order->bar_type_title;
                            }
                            $body="راننده مناسب برای بار ".$bar_type ." از مبدا " .$row_order->start_city_title ." به ".$row_order->destination_city_title ." با شماره پیگیری ".$row_order->tracking_code. " به شما پیشنهاد داده شد. مشاهده جزییات بیشتر در پنل کاربری، بخش سفارشات باز";
                            foreach ($rows->unique('user_api_token') as $row){
                                $fcm_token=$row->user_api_token;
                                push_notification($fcm_token , $body , $title);
                            }
                            $save_date=new Verta();
                            $data = new UserMessage();
                            $data->order_id = $queue_row->order_id;
                            $data->user_id = $order_info->creator_id;
                            $data->driver_id =$driver_id;
                            $data->title = $title;
                            $data->content = $body;
                            $data->type = 1;
                            $data->save_date = str_replace("-","/",$save_date->formatDate());
                            $data->save();
                            $data = array(
                                "ParameterArray" => array(
                                    array(
                                        "Parameter" => "barType",
                                        "ParameterValue" => $bar_type
                                    ),
                                    array(
                                        "Parameter" => "startCityTitle",
                                        "ParameterValue" => $row_order->start_city_title
                                    ),
                                    array(
                                        "Parameter" => "destinationCityTitle",
                                        "ParameterValue" => $row_order->destination_city_title
                                    ),
                                    array(
                                        "Parameter" => "trackingCode",
                                        "ParameterValue" => $row_order->tracking_code
                                    ),
                                ),
                                "Mobile" => $row_order->mobile,
                                "TemplateId" => "46650"
                            );
                            $SmsIR_UltraFastSend = new SmsIR_UltraFastSend();
                            $SmsIR_UltraFastSend->ultraFastSend($data);
                        }
                    }
                }
            }
        })->everyMinute();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
