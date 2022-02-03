<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Warehouse;
use App\Models\Biller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\Tax;
use App\Models\Sale;
use App\Models\Delivery;
use App\Models\PosSetting;
use App\Models\Product_Sale;
use App\Models\Product_Warehouse;
use App\Models\Payment;
use App\Models\Account;
use App\Models\Coupon;
use App\Models\GiftCard;
use App\Models\PaymentWithCheque;
use App\Models\PaymentWithGiftCard;
use App\Models\PaymentWithCreditCard;
use App\Models\PaymentWithPaypal;
use App\Models\User;
use App\Models\Variant;
use App\Models\ProductVariant;
use App\Models\CashRegister;
use App\Models\Returns;
use App\Models\Expense;
use App\Models\ProductPurchase;
use App\Models\ProductBatch;
use App\Models\Purchase;
use App\Models\RewardPointSetting;
use DB;
use App\GeneralSetting;
use Stripe\Stripe;
use NumberToWords\NumberToWords;
use Auth;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Mail\UserNotification;
use Illuminate\Support\Facades\Mail;
use Srmklive\PayPal\Services\ExpressCheckout;
use Srmklive\PayPal\Services\AdaptivePayments;
use GeniusTS\HijriDate\Date;
use Illuminate\Support\Facades\Validator;

class SaleController extends Controller
{
   
    public function index(Request $request)
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('sales-index')) {
            $permissions = Role::findByName($role->name)->permissions;
            foreach ($permissions as $permission)
                $all_permission[] = $permission->name;
            if(empty($all_permission))
                $all_permission[] = 'dummy text';
            
            if($request->input('warehouse_id'))
                $warehouse_id = $request->input('warehouse_id');
            else
                $warehouse_id = 0;

            if($request->input('starting_date')) {
                $starting_date = $request->input('starting_date');
                $ending_date = $request->input('ending_date');
            }
            else {
                $starting_date = date("Y-m-d", strtotime(date('Y-m-d', strtotime('-1 year', strtotime(date('Y-m-d') )))));
                $ending_date = date("Y-m-d");
            }

            $lims_gift_card_list = GiftCard::where("is_active", true)->get();  // Not use
            $lims_pos_setting_data = PosSetting::latest()->first(); // Not use
            $lims_reward_point_setting_data = RewardPointSetting::latest()->first(); // Not use
            $lims_warehouse_list = Warehouse::where('is_active', true)->get(); 
            $lims_account_list = Account::where('is_active', true)->get();

            return view('sale.index',compact('starting_date', 'ending_date', 'warehouse_id', 'lims_gift_card_list', 'lims_pos_setting_data', 'lims_reward_point_setting_data', 'lims_account_list', 'lims_warehouse_list', 'all_permission'));
        }
        else
            return redirect()->back()->with('not_permitted', 'Sorry! You are not allowed to access this module');
    }

    // Select Data
    public function saleData(Request $request)
    {
        
        $columns = array( 
            1 => 'created_at', 
            2 => 'reference_no',
            7 => 'grand_total',
            8 => 'paid_amount',
        );
        
        $warehouse_id = $request->input('warehouse_id');

        if(Auth::user()->role_id > 2 && config('staff_access') == 'own')
            $totalData = Sale::where('user_id', Auth::id())
                        ->whereDate('created_at', '>=' ,$request->input('starting_date'))
                        ->whereDate('created_at', '<=' ,$request->input('ending_date'))
                        ->count();
        elseif($warehouse_id != 0)
            $totalData = Sale::where('warehouse_id', $warehouse_id)->whereDate('created_at', '>=' ,$request->input('starting_date'))->whereDate('created_at', '<=' ,$request->input('ending_date'))->count();
        else
            $totalData = Sale::whereDate('created_at', '>=' ,$request->input('starting_date'))->whereDate('created_at', '<=' ,$request->input('ending_date'))->count();

        $totalFiltered = $totalData;
        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');
        $order = 'sales.'.$columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        if(empty($request->input('search.value'))) {  // without search value
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own')
                $sales = Sale::with('biller', 'customer', 'warehouse', 'user')
                        ->where('user_id', Auth::id()) // search by user id
                        ->whereDate('created_at', '>=' ,$request->input('starting_date'))
                        ->whereDate('created_at', '<=' ,$request->input('ending_date'))
                        ->offset($start)
                        ->limit($limit)
                        ->orderBy($order, $dir)
                        ->get();
            elseif($warehouse_id != 0)
                $sales = Sale::with('biller', 'customer', 'warehouse', 'user')
                        ->where('warehouse_id', $warehouse_id)  // search by warehouse
                        ->whereDate('created_at', '>=' ,$request->input('starting_date'))
                        ->whereDate('created_at', '<=' ,$request->input('ending_date'))
                        ->offset($start)
                        ->limit($limit)
                        ->orderBy($order, $dir)
                        ->get();
            else
                $sales = Sale::with('biller', 'customer', 'warehouse', 'user')
                        ->whereDate('created_at', '>=' ,$request->input('starting_date'))
                        ->whereDate('created_at', '<=' ,$request->input('ending_date'))
                        ->offset($start)
                        ->limit($limit)
                        ->orderBy($order, $dir)
                        ->get();
        }
        else
        {
            $search = $request->input('search.value'); // with search value
            if(Auth::user()->role_id > 2 && config('staff_access') == 'own') {
                $sales =  Sale::select('sales.*')
                            ->with('biller', 'customer', 'warehouse', 'user')
                            ->join('customers', 'sales.customer_id', '=', 'customers.id')
                            ->join('billers', 'sales.biller_id', '=', 'billers.id')
                            ->whereDate('sales.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))))
                            ->where('sales.user_id', Auth::id())
                            ->orwhere([
                                ['sales.reference_no', 'LIKE', "%{$search}%"],
                                ['sales.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['customers.name', 'LIKE', "%{$search}%"],
                                ['sales.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['customers.phone_number', 'LIKE', "%{$search}%"],
                                ['sales.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['billers.name', 'LIKE', "%{$search}%"],
                                ['sales.user_id', Auth::id()]
                            ])
                            ->offset($start)
                            ->limit($limit)
                            ->orderBy($order,$dir)->get();

                $totalFiltered = Sale::
                            join('customers', 'sales.customer_id', '=', 'customers.id')
                            ->join('billers', 'sales.biller_id', '=', 'billers.id')
                            ->whereDate('sales.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))))
                            ->where('sales.user_id', Auth::id())
                            ->orwhere([
                                ['sales.reference_no', 'LIKE', "%{$search}%"],
                                ['sales.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['customers.name', 'LIKE', "%{$search}%"],
                                ['sales.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['customers.phone_number', 'LIKE', "%{$search}%"],
                                ['sales.user_id', Auth::id()]
                            ])
                            ->orwhere([
                                ['billers.name', 'LIKE', "%{$search}%"],
                                ['sales.user_id', Auth::id()]
                            ])
                            ->count();
            }
            else {
                $sales =  Sale::select('sales.*')
                            ->with('biller', 'customer', 'warehouse', 'user')
                            ->join('customers', 'sales.customer_id', '=', 'customers.id')
                            ->join('billers', 'sales.biller_id', '=', 'billers.id')
                            ->whereDate('sales.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))))
                            ->where('sales.user_id', Auth::id())
                            ->orwhere('sales.reference_no', 'LIKE', "%{$search}%")
                            ->orwhere('customers.name', 'LIKE', "%{$search}%")
                            ->orwhere('customers.phone_number', 'LIKE', "%{$search}%")
                            ->orwhere('billers.name', 'LIKE', "%{$search}%")
                            ->offset($start)
                            ->limit($limit)
                            ->orderBy($order,$dir)->get();

                $totalFiltered = Sale::
                            join('customers', 'sales.customer_id', '=', 'customers.id')
                            ->join('billers', 'sales.biller_id', '=', 'billers.id')
                            ->whereDate('sales.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))))
                            ->where('sales.user_id', Auth::id())
                            ->orwhere('sales.reference_no', 'LIKE', "%{$search}%")
                            ->orwhere('customers.name', 'LIKE', "%{$search}%")
                            ->orwhere('customers.phone_number', 'LIKE', "%{$search}%")
                            ->orwhere('billers.name', 'LIKE', "%{$search}%")
                            ->count();
            }
        }
        $data = array();
        if(!empty($sales))
        {
            foreach ($sales as $key=>$sale)
            {
                $nestedData['id'] = $sale->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($sale->created_at->toDateString()));
                $nestedData['reference_no'] = $sale->reference_no;
                $nestedData['biller'] = $sale->biller->name;
                $nestedData['customer'] = $sale->customer->name.'<input type="hidden" class="deposit" value="'.($sale->customer->deposit - $sale->customer->expense).'" />'.'<input type="hidden" class="points" value="'.$sale->customer->points.'" />';

                // sale status
                if($sale->sale_status == 1){
                    $nestedData['sale_status'] = '<div class="badge badge-success">'.trans('file.Completed').'</div>';
                    $sale_status = trans('file.Completed');
                }
                elseif($sale->sale_status == 2){
                    $nestedData['sale_status'] = '<div class="badge badge-danger">'.trans('file.Pending').'</div>';
                    $sale_status = trans('file.Pending');
                }
                else{
                    $nestedData['sale_status'] = '<div class="badge badge-warning">'.trans('file.Draft').'</div>';
                    $sale_status = trans('file.Draft');
                }

                // payment status
                if($sale->payment_status == 1)
                    $nestedData['payment_status'] = '<div class="badge badge-danger">'.trans('file.Pending').'</div>';
                elseif($sale->payment_status == 2)
                    $nestedData['payment_status'] = '<div class="badge badge-danger">'.trans('file.Due').'</div>';
                elseif($sale->payment_status == 3)
                    $nestedData['payment_status'] = '<div class="badge badge-warning">'.trans('file.Partial').'</div>';
                else
                    $nestedData['payment_status'] = '<div class="badge badge-success">'.trans('file.Paid').'</div>';

                $nestedData['grand_total'] = number_format($sale->grand_total, 2);
                $nestedData['paid_amount'] = number_format($sale->paid_amount, 2);
                $nestedData['due'] = number_format($sale->grand_total - $sale->paid_amount, 2);
                $nestedData['options'] = '<div class="btn-group">
                            <button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">'.trans("file.action").'
                              <span class="caret"></span>
                              <span class="sr-only">Toggle Dropdown</span>
                            </button>
                            <ul class="dropdown-menu edit-options dropdown-menu-right dropdown-default" user="menu">
                                <li><a href="'.route('sale.invoice', $sale->id).'" class="btn btn-link"><i class="fa fa-copy"></i> '.trans('file.Generate Invoice').'</a></li>
                                <li>
                                    <button type="button" class="btn btn-link view"><i class="fa fa-eye"></i> '.trans('file.View').'</button>
                                </li>';
                if(in_array("sales-edit", $request['all_permission'])){
                    if($sale->sale_status != 3)
                        $nestedData['options'] .= '<li>
                            <a href="'.route('sales.edit', $sale->id).'" class="btn btn-link"><i class="dripicons-document-edit"></i> '.trans('file.edit').'</a>
                            </li>';
                    else
                        $nestedData['options'] .= '<li>
                            <a href="'.url('sales/'.$sale->id.'/create').'" class="btn btn-link"><i class="dripicons-document-edit"></i> '.trans('file.edit').'</a>
                        </li>';
                }
                $nestedData['options'] .= 
                    '<li>
                        <button type="button" class="add-payment btn btn-link" data-id = "'.$sale->id.'" data-toggle="modal" data-target="#add-payment"><i class="fa fa-plus"></i> '.trans('file.Add Payment').'</button>
                    </li>
                    <li>
                        <button type="button" class="get-payment btn btn-link" data-id = "'.$sale->id.'"><i class="fa fa-money"></i> '.trans('file.View Payment').'</button>
                    </li>
                    <li>
                        <button type="button" class="add-delivery btn btn-link" data-id = "'.$sale->id.'"><i class="fa fa-truck"></i> '.trans('file.Add Delivery').'</button>
                    </li>';
                if(in_array("sales-delete", $request['all_permission']))
                    $nestedData['options'] .= \Form::open(["route" => ["sales.destroy", $sale->id], "method" => "DELETE"] ).'
                            <li>
                              <button type="submit" class="btn btn-link" onclick="return confirmDelete()"><i class="dripicons-trash"></i> '.trans("file.delete").'</button> 
                            </li>'.\Form::close().'
                        </ul>
                    </div>';
                // data for sale details by one click
                $coupon = Coupon::find($sale->coupon_id);
                if($coupon)
                    $coupon_code = $coupon->code;
                else
                    $coupon_code = null;

                $nestedData['sale'] = array( '[ "'.date(config('date_format'), strtotime($sale->created_at->toDateString())).'"', ' "'.$sale->reference_no.'"', ' "'.$sale_status.'"', ' "'.$sale->biller->name.'"', ' "'.$sale->biller->company_name.'"', ' "'.$sale->biller->email.'"', ' "'.$sale->biller->phone_number.'"', ' "'.$sale->biller->address.'"', ' "'.$sale->biller->city.'"', ' "'.$sale->customer->name.'"', ' "'.$sale->customer->phone_number.'"', ' "'.$sale->customer->address.'"', ' "'.$sale->customer->city.'"', ' "'.$sale->id.'"', ' "'.$sale->total_tax.'"', ' "'.$sale->total_discount.'"', ' "'.$sale->total_price.'"', ' "'.$sale->order_tax.'"', ' "'.$sale->order_tax_rate.'"', ' "'.$sale->order_discount.'"', ' "'.$sale->shipping_cost.'"', ' "'.$sale->grand_total.'"', ' "'.$sale->paid_amount.'"', ' "'.preg_replace('/[\n\r]/', "<br>", $sale->sale_note).'"', ' "'.preg_replace('/[\n\r]/', "<br>", $sale->staff_note).'"', ' "'.$sale->user->name.'"', ' "'.$sale->user->email.'"', ' "'.$sale->warehouse->name.'"', ' "'.$coupon_code.'"', ' "'.$sale->coupon_discount.'"]'
                );
                $data[] = $nestedData;
            }
        }
        $json_data = array(
            "draw"            => intval($request->input('draw')),  
            "recordsTotal"    => intval($totalData),  
            "recordsFiltered" => intval($totalFiltered), 
            "data"            => $data   
        );
            
        echo json_encode($json_data);
    }

    public function genInvoice($id)
    {
        $lims_sale_data = Sale::find($id);
        $lims_product_sale_data = Product_Sale::where('sale_id', $id)->get();
        $lims_biller_data = Biller::find($lims_sale_data->biller_id);
        $lims_warehouse_data = Warehouse::find($lims_sale_data->warehouse_id);
        $lims_customer_data = Customer::find($lims_sale_data->customer_id);
        $lims_payment_data = Payment::where('sale_id', $id)->get();

        $numberToWords = new NumberToWords();
        if(\App::getLocale() == 'ar' || \App::getLocale() == 'hi' || \App::getLocale() == 'vi' || \App::getLocale() == 'en-gb')
            $numberTransformer = $numberToWords->getNumberTransformer('en');
        else
            $numberTransformer = $numberToWords->getNumberTransformer(\App::getLocale());
        $numberInWords = $numberTransformer->toWords($lims_sale_data->grand_total);

        return view('sale.invoice', compact('lims_sale_data', 'lims_product_sale_data', 'lims_biller_data', 'lims_warehouse_data', 'lims_customer_data', 'lims_payment_data', 'numberInWords'));
    }

    public function getProduct($id)
    {
        $lims_product_warehouse_data = Product::join('product_warehouse', 'products.id', '=', 'product_warehouse.product_id')
        ->where([
            ['products.is_active', true],
            ['product_warehouse.warehouse_id', $id],
            ['product_warehouse.qty', '>', 0]
        ])
        ->whereNull('product_warehouse.variant_id')
        ->whereNull('product_warehouse.product_batch_id')
        ->select('product_warehouse.*')
        ->get();

        config()->set('database.connections.mysql.strict', false);
        \DB::reconnect(); //important as the existing connection if any would be in strict mode

        $lims_product_with_batch_warehouse_data = Product::join('product_warehouse', 'products.id', '=', 'product_warehouse.product_id')
        ->where([
            ['products.is_active', true],
            ['product_warehouse.warehouse_id', $id],
            ['product_warehouse.qty', '>', 0]
        ])
        ->whereNull('product_warehouse.variant_id')
        ->whereNotNull('product_warehouse.product_batch_id')
        ->select('product_warehouse.*')
        ->groupBy('product_warehouse.product_id')
        ->get();

        //now changing back the strict ON
        config()->set('database.connections.mysql.strict', true);
        \DB::reconnect();

        $lims_product_with_variant_warehouse_data = Product::join('product_warehouse', 'products.id', '=', 'product_warehouse.product_id')
        ->where([
            ['products.is_active', true],
            ['product_warehouse.warehouse_id', $id],
            ['product_warehouse.qty', '>', 0]
        ])->whereNotNull('product_warehouse.variant_id')->select('product_warehouse.*')->get();
        
        $product_code = [];
        $product_name = [];
        $product_qty = [];
        $product_price = [];
        $product_data = [];
        //product without variant
        foreach ($lims_product_warehouse_data as $product_warehouse) 
        {
            $product_qty[] = $product_warehouse->qty;
            $product_price[] = $product_warehouse->price;
            $lims_product_data = Product::find($product_warehouse->product_id);
            $product_code[] =  $lims_product_data->code;
            $product_name[] = htmlspecialchars($lims_product_data->name);
            $product_type[] = $lims_product_data->type;
            $product_id[] = $lims_product_data->id;
            $product_list[] = $lims_product_data->product_list;
            $qty_list[] = $lims_product_data->qty_list;
            $batch_no[] = null;
            $product_batch_id[] = null;
            $expired_date[] = null;
        }
        //product with batches
        foreach ($lims_product_with_batch_warehouse_data as $product_warehouse) 
        {
            $product_qty[] = $product_warehouse->qty;
            $product_price[] = $product_warehouse->price;
            $lims_product_data = Product::find($product_warehouse->product_id);
            $product_code[] =  $lims_product_data->code;
            $product_name[] = htmlspecialchars($lims_product_data->name);
            $product_type[] = $lims_product_data->type;
            $product_id[] = $lims_product_data->id;
            $product_list[] = $lims_product_data->product_list;
            $qty_list[] = $lims_product_data->qty_list;
            $product_batch_data = ProductBatch::select('id', 'batch_no', 'expired_date')->find($product_warehouse->product_batch_id);
            $batch_no[] = $product_batch_data->batch_no;
            $product_batch_id[] = $product_batch_data->id;
            $expired_date[] = date(config('date_format'), strtotime($product_batch_data->expired_date));
        }
        //product with variant
        foreach ($lims_product_with_variant_warehouse_data as $product_warehouse) 
        {
            $product_qty[] = $product_warehouse->qty;
            $lims_product_data = Product::find($product_warehouse->product_id);
            $lims_product_variant_data = ProductVariant::select('item_code')->FindExactProduct($product_warehouse->product_id, $product_warehouse->variant_id)->first();
            $product_code[] =  $lims_product_variant_data->item_code;
            $product_name[] = htmlspecialchars($lims_product_data->name);
            $product_type[] = $lims_product_data->type;
            $product_id[] = $lims_product_data->id;
            $product_list[] = $lims_product_data->product_list;
            $qty_list[] = $lims_product_data->qty_list;
            $batch_no[] = null;
            $product_batch_id[] = null;
            $expired_date[] = null;
        }
        //retrieve product with type of digital, combo and service
        $lims_product_data = Product::whereNotIn('type', ['standard'])->where('is_active', true)->get();
        foreach ($lims_product_data as $product) 
        {
            $product_qty[] = $product->qty;
            $product_code[] =  $product->code;
            $product_name[] = $product->name;
            $product_type[] = $product->type;
            $product_id[] = $product->id;
            $product_list[] = $product->product_list;
            $qty_list[] = $product->qty_list;
            $batch_no[] = null;
            $product_batch_id[] = null;
            $expired_date[] = null;
        }
        $product_data = [$product_code, $product_name, $product_qty, $product_type, $product_id, $product_list, $qty_list, $product_price, $batch_no, $product_batch_id, $expired_date];
        return $product_data;
    }

    public function productSaleData($id)
    {
        $lims_product_sale_data = Product_Sale::where('sale_id', $id)->get();
        foreach ($lims_product_sale_data as $key => $product_sale_data) {
            $product = Product::find($product_sale_data->product_id);
            if($product_sale_data->variant_id) {
                $lims_product_variant_data = ProductVariant::select('item_code')->FindExactProduct($product_sale_data->product_id, $product_sale_data->variant_id)->first();
                $product->code = $lims_product_variant_data->item_code;
            }
            $unit_data = Unit::find($product_sale_data->sale_unit_id);
            if($unit_data){
                $unit = $unit_data->unit_code;
            }
            else
                $unit = '';
            if($product_sale_data->product_batch_id) {
                $product_batch_data = ProductBatch::select('batch_no')->find($product_sale_data->product_batch_id);
                $product_sale[7][$key] = $product_batch_data->batch_no;
            }
            else
                $product_sale[7][$key] = 'N/A';
            $product_sale[0][$key] = $product->name . ' [' . $product->code . ']';
            if($product_sale_data->imei_number)
                $product_sale[0][$key] .= '<br>IMEI or Serial Number: '. $product_sale_data->imei_number;
            $product_sale[1][$key] = $product_sale_data->qty;
            $product_sale[2][$key] = $unit;
            $product_sale[3][$key] = $product_sale_data->tax;
            $product_sale[4][$key] = $product_sale_data->tax_rate;
            $product_sale[5][$key] = $product_sale_data->discount;
            $product_sale[6][$key] = $product_sale_data->total;
        }
        return $product_sale;
    }

    public function getCustomerGroup($id)
    {
         $lims_customer_data = Customer::find($id);
         $lims_customer_group_data = CustomerGroup::find($lims_customer_data->customer_group_id);
         return $lims_customer_group_data->percentage;
    }

    // type ahead product
    public function limsProductSearch(Request $request)
    {
        $todayDate = date('Y-m-d');
        $product_code = explode("(", $request['data']);
        $product_code[0] = rtrim($product_code[0], " ");
        $product_variant_id = null;
        $lims_product_data = Product::where([
            ['code', $product_code[0]],
            ['is_active', true]
        ])->first();
        if(!$lims_product_data) {
            $lims_product_data = Product::join('product_variants', 'products.id', 'product_variants.product_id')
                ->select('products.*', 'product_variants.id as product_variant_id', 'product_variants.item_code', 'product_variants.additional_price')
                ->where([
                    ['product_variants.item_code', $product_code[0]],
                    ['products.is_active', true]
                ])->first();
            $product_variant_id = $lims_product_data->product_variant_id;
        }

        $product[] = $lims_product_data->name;
        if($lims_product_data->is_variant){
            $product[] = $lims_product_data->item_code;
            $lims_product_data->price += $lims_product_data->additional_price;
        }
        else
            $product[] = $lims_product_data->code;

        if($lims_product_data->promotion && $todayDate <= $lims_product_data->last_date){
            $product[] = $lims_product_data->promotion_price;
        }
        else
            $product[] = $lims_product_data->price;
        
        if($lims_product_data->tax_id) {
            $lims_tax_data = Tax::find($lims_product_data->tax_id);
            $product[] = $lims_tax_data->rate;
            $product[] = $lims_tax_data->name;
        }
        else{
            $product[] = 0;
            $product[] = 'No Tax';
        }
        $product[] = $lims_product_data->tax_method;
        if($lims_product_data->type == 'standard'){
            $units = Unit::where("base_unit", $lims_product_data->unit_id)
                    ->orWhere('id', $lims_product_data->unit_id)
                    ->get();
            $unit_name = array();
            $unit_operator = array();
            $unit_operation_value = array();
            foreach ($units as $unit) {
                if($lims_product_data->sale_unit_id == $unit->id) {
                    array_unshift($unit_name, $unit->unit_name);
                    array_unshift($unit_operator, $unit->operator);
                    array_unshift($unit_operation_value, $unit->operation_value);
                }
                else {
                    $unit_name[]  = $unit->unit_name;
                    $unit_operator[] = $unit->operator;
                    $unit_operation_value[] = $unit->operation_value;
                }
            }
            $product[] = implode(",",$unit_name) . ',';
            $product[] = implode(",",$unit_operator) . ',';
            $product[] = implode(",",$unit_operation_value) . ',';     
        }
        else{
            $product[] = 'n/a'. ',';
            $product[] = 'n/a'. ',';
            $product[] = 'n/a'. ',';
        }
        $product[] = $lims_product_data->id;
        $product[] = $product_variant_id;
        $product[] = $lims_product_data->promotion;
        $product[] = $lims_product_data->is_batch;
        $product[] = $lims_product_data->is_imei;
        return $product;

    }

   
    public function create()
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('sales-add')) {
            $lims_customer_list = Customer::where('is_active', true)->get();
            if(Auth::user()->role_id > 2) {
                $lims_warehouse_list = Warehouse::where([
                    ['is_active', true],
                    ['id', Auth::user()->warehouse_id]
                ])->get();
                $lims_biller_list = Biller::where([
                    ['is_active', true],
                    ['id', Auth::user()->biller_id]
                ])->get();
            }
            else {
                $lims_warehouse_list = Warehouse::where('is_active', true)->get();
                $lims_biller_list = Biller::where('is_active', true)->get();
            }

            $lims_tax_list = Tax::where('is_active', true)->get();
            $lims_pos_setting_data = PosSetting::latest()->first();
            $lims_reward_point_setting_data = RewardPointSetting::latest()->first();

            return view('sale.create',compact('lims_customer_list', 'lims_warehouse_list', 'lims_biller_list', 'lims_pos_setting_data', 'lims_tax_list', 'lims_reward_point_setting_data'));
        }
        else
            return redirect()->back()->with('not_permitted', 'Sorry! You are not allowed to access this module');
    }

    public function store(Request $request)
    {
        $data = $request->all();
        if(isset($request->reference_no)) {
            $this->validate($request, [
                'reference_no' => [
                    'max:191', 'required', 'unique:sales'
                ],
            ]);
        }
        //return dd($data);
        $data['user_id'] = Auth::id();
        $cash_register_data = CashRegister::where([
            ['user_id', $data['user_id']],
            ['warehouse_id', $data['warehouse_id']],
            ['status', true]
        ])->first();

        if($cash_register_data)
            $data['cash_register_id'] = $cash_register_data->id;

        if($data['pos']) {
            if(!isset($data['reference_no']))
                $data['reference_no'] = 'posr-' . date("Ymd") . '-'. date("his");

            $balance = $data['grand_total'] - $data['paid_amount'];
            if($balance > 0 || $balance < 0)
                $data['payment_status'] = 2;
            else
                $data['payment_status'] = 4;

            if($data['draft']) {
                $lims_sale_data = Sale::find($data['sale_id']);
                $lims_product_sale_data = Product_Sale::where('sale_id', $data['sale_id'])->get();
                foreach ($lims_product_sale_data as $product_sale_data) {
                    $product_sale_data->delete();
                }
                $lims_sale_data->delete();
            }
        }
        else {
            if(!isset($data['reference_no']))
                $data['reference_no'] = 'SR-' . date("Ymd") . '-'. date("his");
        }

        $document = $request->document;
        if ($document) {
            $v = Validator::make(
                [
                    'extension' => strtolower($request->document->getClientOriginalExtension()),
                ],
                [
                    'extension' => 'in:jpg,jpeg,png,gif,pdf,csv,docx,xlsx,txt',
                ]
            );
            if ($v->fails())
                return redirect()->back()->withErrors($v->errors());

            $documentName = $document->getClientOriginalName();
            $document->move('storage/sale/documents', $documentName);
            $data['document'] = $documentName;
        }
        if($data['coupon_active']) {
            $lims_coupon_data = Coupon::find($data['coupon_id']);
            $lims_coupon_data->used += 1;
            $lims_coupon_data->save();
        }

        $lims_sale_data = Sale::create($data);
        $lims_customer_data = Customer::find($data['customer_id']);
        $lims_reward_point_setting_data = RewardPointSetting::latest()->first();
        //checking if customer gets some points or not
        if($lims_reward_point_setting_data->is_active &&  $data['grand_total'] >= $lims_reward_point_setting_data->minimum_amount) {
            $point = (int)($data['grand_total'] / $lims_reward_point_setting_data->per_point_amount);
            $lims_customer_data->points += $point;
            $lims_customer_data->save();
        }
        
        //collecting male data
        $mail_data['email'] = $lims_customer_data->email;
        $mail_data['reference_no'] = $lims_sale_data->reference_no;
        $mail_data['sale_status'] = $lims_sale_data->sale_status;
        $mail_data['payment_status'] = $lims_sale_data->payment_status;
        $mail_data['total_qty'] = $lims_sale_data->total_qty;
        $mail_data['total_price'] = $lims_sale_data->total_price;
        $mail_data['order_tax'] = $lims_sale_data->order_tax;
        $mail_data['order_tax_rate'] = $lims_sale_data->order_tax_rate;
        $mail_data['order_discount'] = $lims_sale_data->order_discount;
        // $mail_data['shipping_cost'] = $lims_sale_data->shipping_cost;
        $mail_data['grand_total'] = $lims_sale_data->grand_total;
        $mail_data['paid_amount'] = $lims_sale_data->paid_amount;

        $product_id = $data['product_id'];
        $product_batch_id = $data['product_batch_id'];
        $imei_number = $data['imei_number'];
        $product_code = $data['product_code'];
        $qty = $data['qty'];
        $sale_unit = $data['sale_unit'];
        $net_unit_price = $data['net_unit_price'];
        $discount = $data['discount'];
        $tax_rate = $data['tax_rate'];
        $tax = $data['tax'];
        $total = $data['subtotal'];
        $product_sale = [];

        foreach ($product_id as $i => $id) {
            $lims_product_data = Product::where('id', $id)->first();
            $product_sale['variant_id'] = null;
            $product_sale['product_batch_id'] = null;
            if($lims_product_data->type == 'combo' && $data['sale_status'] == 1){
                $product_list = explode(",", $lims_product_data->product_list);
                $variant_list = explode(",", $lims_product_data->variant_list);
                if($lims_product_data->variant_list)
                    $variant_list = explode(",", $lims_product_data->variant_list);
                else
                    $variant_list = [];
                $qty_list = explode(",", $lims_product_data->qty_list);
                $price_list = explode(",", $lims_product_data->price_list);
                
                foreach ($product_list as $key=>$child_id) {
                    $child_data = Product::find($child_id);
                    if(count($variant_list) && $variant_list[$key]) {
                        $child_product_variant_data = ProductVariant::where([
                            ['product_id', $child_id],
                            ['variant_id', $variant_list[$key]]
                        ])->first();

                        $child_warehouse_data = Product_Warehouse::where([
                            ['product_id', $child_id],
                            ['variant_id', $variant_list[$key]],
                            ['warehouse_id', $data['warehouse_id'] ],
                        ])->first();

                        $child_product_variant_data->qty -= $qty[$i] * $qty_list[$key];
                        $child_product_variant_data->save();
                    }
                    else {
                        $child_warehouse_data = Product_Warehouse::where([
                            ['product_id', $child_id],
                            ['warehouse_id', $data['warehouse_id'] ],
                        ])->first();
                    }

                    $child_data->qty -= $qty[$i] * $qty_list[$key];
                    $child_warehouse_data->qty -= $qty[$i] * $qty_list[$key];

                    $child_data->save();
                    $child_warehouse_data->save();
                }
            }

            if($sale_unit[$i] != 'n/a') {
                $lims_sale_unit_data  = Unit::where('unit_name', $sale_unit[$i])->first();
                $sale_unit_id = $lims_sale_unit_data->id;
                if($lims_product_data->is_variant) {
                    $lims_product_variant_data = ProductVariant::select('id', 'variant_id', 'qty')->FindExactProductWithCode($id, $product_code[$i])->first();
                    $product_sale['variant_id'] = $lims_product_variant_data->variant_id;
                }
                if($lims_product_data->is_batch && $product_batch_id[$i]) {
                    $product_sale['product_batch_id'] = $product_batch_id[$i];
                }

                if($data['sale_status'] == 1) {
                    if($lims_sale_unit_data->operator == '*')
                        $quantity = $qty[$i] * $lims_sale_unit_data->operation_value;
                    elseif($lims_sale_unit_data->operator == '/')
                        $quantity = $qty[$i] / $lims_sale_unit_data->operation_value;
                    //deduct quantity
                    $lims_product_data->qty = $lims_product_data->qty - $quantity;
                    $lims_product_data->save();
                    //deduct product variant quantity if exist
                    if($lims_product_data->is_variant) {
                        $lims_product_variant_data->qty -= $quantity;
                        $lims_product_variant_data->save();
                        $lims_product_warehouse_data = Product_Warehouse::FindProductWithVariant($id, $lims_product_variant_data->variant_id, $data['warehouse_id'])->first();
                    }
                    elseif($product_batch_id[$i]) {
                        $lims_product_warehouse_data = Product_Warehouse::where([
                            ['product_batch_id', $product_batch_id[$i] ],
                            ['warehouse_id', $data['warehouse_id'] ]
                        ])->first();
                        $lims_product_batch_data = ProductBatch::find($product_batch_id[$i]);
                        //deduct product batch quantity
                        $lims_product_batch_data->qty -= $quantity;
                        $lims_product_batch_data->save();
                    }
                    else {
                        $lims_product_warehouse_data = Product_Warehouse::FindProductWithoutVariant($id, $data['warehouse_id'])->first();
                    }
                    //deduct quantity from warehouse
                    $lims_product_warehouse_data->qty -= $quantity;
                    $lims_product_warehouse_data->save();
                }
            }
            else
                $sale_unit_id = 0;
            if($product_sale['variant_id']) {
                $variant_data = Variant::select('name')->find($product_sale['variant_id']);
                $mail_data['products'][$i] = $lims_product_data->name . ' ['. $variant_data->name .']';
            }
            else
                $mail_data['products'][$i] = $lims_product_data->name;
            //deduct imei number if available
            if($imei_number[$i]) {
                $imei_numbers = explode(",", $imei_number[$i]);
                $all_imei_numbers = explode(",", $lims_product_warehouse_data->imei_number);
                foreach ($imei_numbers as $number) {
                    if (($j = array_search($number, $all_imei_numbers)) !== false) {
                        unset($all_imei_numbers[$j]);
                    }
                }
                $lims_product_warehouse_data->imei_number = implode(",", $all_imei_numbers);
                $lims_product_warehouse_data->save();   
            }
            if($lims_product_data->type == 'digital')
                $mail_data['file'][$i] = url('/public/product/files').'/'.$lims_product_data->file;
            else
                $mail_data['file'][$i] = '';
            if($sale_unit_id)
                $mail_data['unit'][$i] = $lims_sale_unit_data->unit_code;
            else
                $mail_data['unit'][$i] = '';

            $product_sale['sale_id'] = $lims_sale_data->id ;
            $product_sale['product_id'] = $id;
            $product_sale['imei_number'] = $imei_number[$i];
            $product_sale['qty'] = $mail_data['qty'][$i] = $qty[$i];
            $product_sale['sale_unit_id'] = $sale_unit_id;
            $product_sale['net_unit_price'] = $net_unit_price[$i];
            $product_sale['discount'] = $discount[$i];
            $product_sale['tax_rate'] = $tax_rate[$i];
            $product_sale['tax'] = $tax[$i];
            $product_sale['total'] = $mail_data['total'][$i] = $total[$i];
            Product_Sale::create($product_sale);
        }
        if($data['sale_status'] == 3)
            $message = 'Sale successfully added to draft';
        else
            $message = ' Sale created successfully';
        if($mail_data['email'] && $data['sale_status'] == 1) {
            try {
                Mail::send( 'mail.sale_details', $mail_data, function( $message ) use ($mail_data)
                {
                    $message->to( $mail_data['email'] )->subject( 'Sale Details' );
                });
            }
            catch(\Exception $e){
                $message = ' Sale created successfully. Please setup your <a href="setting/mail_setting">mail setting</a> to send mail.';
            }
        }

        if($data['payment_status'] == 3 || $data['payment_status'] == 4 || ($data['payment_status'] == 2 && $data['pos'] && $data['paid_amount'] > 0)) {
            
            $lims_payment_data = new Payment();
            $lims_payment_data->user_id = Auth::id();

            if($data['paid_by_id'] == 1)
                $paying_method = 'Cash';
            elseif ($data['paid_by_id'] == 2) {
                $paying_method = 'Gift Card';
            }
            elseif ($data['paid_by_id'] == 3)
                $paying_method = 'Credit Card';
            elseif ($data['paid_by_id'] == 4)
                $paying_method = 'Cheque';
            elseif ($data['paid_by_id'] == 5)
                $paying_method = 'Paypal';
            elseif($data['paid_by_id'] == 6)
                $paying_method = 'Deposit';
            elseif($data['paid_by_id'] == 7) {
                $paying_method = 'Points';
                $lims_payment_data->used_points = $data['used_points'];
            }

            if($cash_register_data)
                $lims_payment_data->cash_register_id = $cash_register_data->id;
                $lims_account_data = Account::where('is_default', true)->first();
                $lims_payment_data->account_id = $lims_account_data->id;
                $lims_payment_data->sale_id = $lims_sale_data->id;
                $data['payment_reference'] = 'spr-'.date("Ymd").'-'.date("his");
                $lims_payment_data->payment_reference = $data['payment_reference'];
                $lims_payment_data->amount = $data['paid_amount'];
                $lims_payment_data->change = $data['paying_amount'] - $data['paid_amount'];
                $lims_payment_data->paying_method = $paying_method;
                $lims_payment_data->payment_note = $data['payment_note'];
                $lims_payment_data->save();

                $lims_payment_data = Payment::latest()->first();
                $data['payment_id'] = $lims_payment_data->id;
            if($paying_method == 'Credit Card'){
                $lims_pos_setting_data = PosSetting::latest()->first();
                Stripe::setApiKey($lims_pos_setting_data->stripe_secret_key);
                $token = $data['stripeToken'];
                $grand_total = $data['grand_total'];

                $lims_payment_with_credit_card_data = PaymentWithCreditCard::where('customer_id', $data['customer_id'])->first();

                if(!$lims_payment_with_credit_card_data) {
                    // Create a Customer:
                    $customer = \Stripe\Customer::create([
                        'source' => $token
                    ]);
                    
                    // Charge the Customer instead of the card:
                    $charge = \Stripe\Charge::create([
                        'amount' => $grand_total * 100,
                        'currency' => 'usd',
                        'customer' => $customer->id
                    ]);
                    $data['customer_stripe_id'] = $customer->id;
                }
                else {
                    $customer_id = 
                    $lims_payment_with_credit_card_data->customer_stripe_id;

                    $charge = \Stripe\Charge::create([
                        'amount' => $grand_total * 100,
                        'currency' => 'usd',
                        'customer' => $customer_id, // Previously stored, then retrieved
                    ]);
                    $data['customer_stripe_id'] = $customer_id;
                }
                $data['charge_id'] = $charge->id;
                PaymentWithCreditCard::create($data);
            }
            elseif ($paying_method == 'Gift Card') {
                $lims_gift_card_data = GiftCard::find($data['gift_card_id']);
                $lims_gift_card_data->expense += $data['paid_amount'];
                $lims_gift_card_data->save();
                PaymentWithGiftCard::create($data);
            }
            elseif ($paying_method == 'Cheque') {
                PaymentWithCheque::create($data);
            }
            elseif ($paying_method == 'Paypal') {
                $provider = new ExpressCheckout;
                $paypal_data = [];
                $paypal_data['items'] = [];
                foreach ($data['product_id'] as $key => $product_id) {
                    $lims_product_data = Product::find($product_id);
                    $paypal_data['items'][] = [
                        'name' => $lims_product_data->name,
                        'price' => ($data['subtotal'][$key]/$data['qty'][$key]),
                        'qty' => $data['qty'][$key]
                    ];
                }
                $paypal_data['items'][] = [
                    'name' => 'Order Tax',
                    'price' => $data['order_tax'],
                    'qty' => 1
                ];
                $paypal_data['items'][] = [
                    'name' => 'Order Discount',
                    'price' => $data['order_discount'] * (-1),
                    'qty' => 1
                ];
                $paypal_data['items'][] = [
                    'name' => 'Shipping Cost',
                    'price' => $data['shipping_cost'],
                    'qty' => 1
                ];
                if($data['grand_total'] != $data['paid_amount']){
                    $paypal_data['items'][] = [
                        'name' => 'Due',
                        'price' => ($data['grand_total'] - $data['paid_amount']) * (-1),
                        'qty' => 1
                    ];
                }
                //return $paypal_data;
                $paypal_data['invoice_id'] = $lims_sale_data->reference_no;
                $paypal_data['invoice_description'] = "Reference # {$paypal_data['invoice_id']} Invoice";
                $paypal_data['return_url'] = url('/sale/paypalSuccess');
                $paypal_data['cancel_url'] = url('/sale/create');

                $total = 0;
                foreach($paypal_data['items'] as $item) {
                    $total += $item['price']*$item['qty'];
                }

                $paypal_data['total'] = $total;
                $response = $provider->setExpressCheckout($paypal_data);
                 // This will redirect user to PayPal
                return redirect($response['paypal_link']);
            }
            elseif($paying_method == 'Deposit'){
                $lims_customer_data->expense += $data['paid_amount'];
                $lims_customer_data->save();
            }
            elseif($paying_method == 'Points'){
                $lims_customer_data->points -= $data['used_points'];
                $lims_customer_data->save();
            }
        }
        if($lims_sale_data->sale_status == '1')
            return redirect('sales/gen_invoice/' . $lims_sale_data->id)->with('message', $message);
        elseif($data['pos'])
            return redirect('pos')->with('message', $message);
        else
            return redirect('sales')->with('message', $message);
    }

    
    public function edit($id)
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('sales-edit')){
            $lims_customer_list = Customer::where('is_active', true)->get();
            $lims_warehouse_list = Warehouse::where('is_active', true)->get();
            $lims_biller_list = Biller::where('is_active', true)->get();
            $lims_tax_list = Tax::where('is_active', true)->get();
            $lims_sale_data = Sale::find($id);
            $lims_product_sale_data = Product_Sale::where('sale_id', $id)->get();
            return view('sale.edit',compact('lims_customer_list', 'lims_warehouse_list', 'lims_biller_list', 'lims_tax_list', 'lims_sale_data','lims_product_sale_data'));
        }
        else
            return redirect()->back()->with('not_permitted', 'Sorry! You are not allowed to access this module');
    }

    
    public function update(Request $request, $id)
    {
        $data = $request->except('document');
        //return dd($data);
        $document = $request->document;
        if ($document) {
            $v = Validator::make(
                [
                    'extension' => strtolower($request->document->getClientOriginalExtension()),
                ],
                [
                    'extension' => 'in:jpg,jpeg,png,gif,pdf,csv,docx,xlsx,txt',
                ]
            );
            if ($v->fails())
                return redirect()->back()->withErrors($v->errors());

            $documentName = $document->getClientOriginalName();
            $document->move('storage/sale/documents', $documentName);
            $data['document'] = $documentName;
        }

        $balance = $data['grand_total'] - $data['paid_amount'];
        if($balance < 0 || $balance > 0)
            $data['payment_status'] = 2;
        else
            $data['payment_status'] = 4;

        $lims_sale_data = Sale::find($id);
        $lims_product_sale_data = Product_Sale::where('sale_id', $id)->get();
        $product_id = $data['product_id'];
        $imei_number = $data['imei_number'];
        $product_batch_id = $data['product_batch_id'];
        $product_code = $data['product_code'];
        $product_variant_id = $data['product_variant_id'];
        $qty = $data['qty'];
        $sale_unit = $data['sale_unit'];
        $net_unit_price = $data['net_unit_price'];
        $discount = $data['discount'];
        $tax_rate = $data['tax_rate'];
        $tax = $data['tax'];
        $total = $data['subtotal'];
        $old_product_id = [];
        $product_sale = [];

        foreach ($lims_product_sale_data as  $key => $product_sale_data) {
            $old_product_id[] = $product_sale_data->product_id;
            $old_product_variant_id[] = null;
            $lims_product_data = Product::find($product_sale_data->product_id);

            if( ($lims_sale_data->sale_status == 1) && ($lims_product_data->type == 'combo') ) {
                $product_list = explode(",", $lims_product_data->product_list);
                $variant_list = explode(",", $lims_product_data->variant_list);
                if($lims_product_data->variant_list)
                    $variant_list = explode(",", $lims_product_data->variant_list);
                else
                    $variant_list = [];
                $qty_list = explode(",", $lims_product_data->qty_list);

                foreach ($product_list as $index=>$child_id) {
                    $child_data = Product::find($child_id);
                    if(count($variant_list) && $variant_list[$index]) {
                        $child_product_variant_data = ProductVariant::where([
                            ['product_id', $child_id],
                            ['variant_id', $variant_list[$index]]
                        ])->first();

                        $child_warehouse_data = Product_Warehouse::where([
                            ['product_id', $child_id],
                            ['variant_id', $variant_list[$index]],
                            ['warehouse_id', $lims_sale_data->warehouse_id ],
                        ])->first();

                        $child_product_variant_data->qty += $product_sale_data->qty * $qty_list[$index];
                        $child_product_variant_data->save();
                    }
                    else {
                        $child_warehouse_data = Product_Warehouse::where([
                            ['product_id', $child_id],
                            ['warehouse_id', $lims_sale_data->warehouse_id ],
                        ])->first();
                    }

                    $child_data->qty += $product_sale_data->qty * $qty_list[$index];
                    $child_warehouse_data->qty += $product_sale_data->qty * $qty_list[$index];

                    $child_data->save();
                    $child_warehouse_data->save();
                }
            }
            elseif( ($lims_sale_data->sale_status == 1) && ($product_sale_data->sale_unit_id != 0)) {
                $old_product_qty = $product_sale_data->qty;
                $lims_sale_unit_data = Unit::find($product_sale_data->sale_unit_id);
                if ($lims_sale_unit_data->operator == '*')
                    $old_product_qty = $old_product_qty * $lims_sale_unit_data->operation_value;
                else
                    $old_product_qty = $old_product_qty / $lims_sale_unit_data->operation_value;
                if($product_sale_data->variant_id) {
                    $lims_product_variant_data = ProductVariant::select('id', 'qty')->FindExactProduct($product_sale_data->product_id, $product_sale_data->variant_id)->first();
                    $lims_product_warehouse_data = Product_Warehouse::FindProductWithVariant($product_sale_data->product_id, $product_sale_data->variant_id, $lims_sale_data->warehouse_id)
                    ->first();
                    $old_product_variant_id[$key] = $lims_product_variant_data->id;
                    $lims_product_variant_data->qty += $old_product_qty;
                    $lims_product_variant_data->save();
                }
                elseif($product_sale_data->product_batch_id) {
                    $lims_product_warehouse_data = Product_Warehouse::where([
                        ['product_id', $product_sale_data->product_id],
                        ['product_batch_id', $product_sale_data->product_batch_id],
                        ['warehouse_id', $lims_sale_data->warehouse_id]
                    ])->first();

                    $product_batch_data = ProductBatch::find($product_sale_data->product_batch_id);
                    $product_batch_data->qty += $old_product_qty;
                    $product_batch_data->save();
                }
                else
                    $lims_product_warehouse_data = Product_Warehouse::FindProductWithoutVariant($product_sale_data->product_id, $lims_sale_data->warehouse_id)
                    ->first();
                $lims_product_data->qty += $old_product_qty;
                $lims_product_warehouse_data->qty += $old_product_qty;
                $lims_product_data->save();
                $lims_product_warehouse_data->save();
            }

            if($product_sale_data->imei_number) { // not use
                if($lims_product_warehouse_data->imei_number)
                    $lims_product_warehouse_data->imei_number .= ',' . $product_sale_data->imei_number;
                else
                    $lims_product_warehouse_data->imei_number = $product_sale_data->imei_number;
                $lims_product_warehouse_data->save();
            }

            if($product_sale_data->variant_id && !(in_array($old_product_variant_id[$key], $product_variant_id)) ){
                $product_sale_data->delete();
            }
            elseif( !(in_array($old_product_id[$key], $product_id)) )
                $product_sale_data->delete();
        }
        foreach ($product_id as $key => $pro_id) {
            $lims_product_data = Product::find($pro_id);
            $product_sale['variant_id'] = null;
            if($lims_product_data->type == 'combo' && $data['sale_status'] == 1) {
                $product_list = explode(",", $lims_product_data->product_list);
                $variant_list = explode(",", $lims_product_data->variant_list);
                if($lims_product_data->variant_list)
                    $variant_list = explode(",", $lims_product_data->variant_list);
                else
                    $variant_list = [];
                $qty_list = explode(",", $lims_product_data->qty_list);

                foreach ($product_list as $index => $child_id) {
                    $child_data = Product::find($child_id);
                    if(count($variant_list) && $variant_list[$index]) {
                        $child_product_variant_data = ProductVariant::where([
                            ['product_id', $child_id],
                            ['variant_id', $variant_list[$index] ],
                        ])->first();

                        $child_warehouse_data = Product_Warehouse::where([
                            ['product_id', $child_id],
                            ['variant_id', $variant_list[$index] ],
                            ['warehouse_id', $data['warehouse_id'] ],
                        ])->first();

                        $child_product_variant_data->qty -= $qty[$key] * $qty_list[$index];
                        $child_product_variant_data->save();
                    }
                    else {
                        $child_warehouse_data = Product_Warehouse::where([
                            ['product_id', $child_id],
                            ['warehouse_id', $data['warehouse_id'] ],
                        ])->first();
                    }
                        

                    $child_data->qty -= $qty[$key] * $qty_list[$index];
                    $child_warehouse_data->qty -= $qty[$key] * $qty_list[$index];

                    $child_data->save();
                    $child_warehouse_data->save();
                }
            }
            if($sale_unit[$key] != 'n/a') {
                $lims_sale_unit_data = Unit::where('unit_name', $sale_unit[$key])->first();
                $sale_unit_id = $lims_sale_unit_data->id;
                if($data['sale_status'] == 1) {
                    $new_product_qty = $qty[$key];
                    if ($lims_sale_unit_data->operator == '*') {
                        $new_product_qty = $new_product_qty * $lims_sale_unit_data->operation_value;
                    } else {
                        $new_product_qty = $new_product_qty / $lims_sale_unit_data->operation_value;
                    }
                    if($lims_product_data->is_variant) {
                        $lims_product_variant_data = ProductVariant::select('id', 'variant_id', 'qty')->FindExactProductWithCode($pro_id, $product_code[$key])->first();
                        $lims_product_warehouse_data = Product_Warehouse::FindProductWithVariant($pro_id, $lims_product_variant_data->variant_id, $data['warehouse_id'])
                        ->first();
                        
                        $product_sale['variant_id'] = $lims_product_variant_data->variant_id;
                        $lims_product_variant_data->qty -= $new_product_qty;
                        $lims_product_variant_data->save();
                    }
                    elseif($product_batch_id[$key]) {
                        $lims_product_warehouse_data = Product_Warehouse::where([
                            ['product_id', $pro_id],
                            ['product_batch_id', $product_batch_id[$key] ],
                            ['warehouse_id', $data['warehouse_id'] ]
                        ])->first();

                        $product_batch_data = ProductBatch::find($product_batch_id[$key]);
                        $product_batch_data->qty -= $new_product_qty;
                        $product_batch_data->save();
                    }
                    else {
                        $lims_product_warehouse_data = Product_Warehouse::FindProductWithoutVariant($pro_id, $data['warehouse_id'])
                        ->first();
                    }
                    $lims_product_data->qty -= $new_product_qty;
                    $lims_product_warehouse_data->qty -= $new_product_qty;
                    $lims_product_data->save();
                    $lims_product_warehouse_data->save();
                }
            }
            else
                $sale_unit_id = 0;

            //deduct imei number if available
            if($imei_number[$key]) { // not use
                $imei_numbers = explode(",", $imei_number[$key]);
                $all_imei_numbers = explode(",", $lims_product_warehouse_data->imei_number);
                foreach ($imei_numbers as $number) {
                    if (($j = array_search($number, $all_imei_numbers)) !== false) {
                        unset($all_imei_numbers[$j]);
                    }
                }
                $lims_product_warehouse_data->imei_number = implode(",", $all_imei_numbers);
                $lims_product_warehouse_data->save();   
            }
            
            //collecting mail data
            if($product_sale['variant_id']) {
                $variant_data = Variant::select('name')->find($product_sale['variant_id']);
                $mail_data['products'][$key] = $lims_product_data->name . ' [' . $variant_data->name . ']';
            }
            else
                $mail_data['products'][$key] = $lims_product_data->name;

            if($lims_product_data->type == 'digital')
                $mail_data['file'][$key] = url('/public/product/files').'/'.$lims_product_data->file;
            else
                $mail_data['file'][$key] = '';
            if($sale_unit_id)
                $mail_data['unit'][$key] = $lims_sale_unit_data->unit_code;
            else
                $mail_data['unit'][$key] = '';

            $product_sale['sale_id'] = $id ;
            $product_sale['product_id'] = $pro_id;
            $product_sale['imei_number'] = $imei_number[$key];
            $product_sale['product_batch_id'] = $product_batch_id[$key];
            $product_sale['qty'] = $mail_data['qty'][$key] = $qty[$key];
            $product_sale['sale_unit_id'] = $sale_unit_id;
            $product_sale['net_unit_price'] = $net_unit_price[$key];
            $product_sale['discount'] = $discount[$key];
            $product_sale['tax_rate'] = $tax_rate[$key];
            $product_sale['tax'] = $tax[$key];
            $product_sale['total'] = $mail_data['total'][$key] = $total[$key];
            
            if($product_sale['variant_id'] && in_array($product_variant_id[$key], $old_product_variant_id)) {
                Product_Sale::where([
                    ['product_id', $pro_id],
                    ['variant_id', $product_sale['variant_id']],
                    ['sale_id', $id]
                ])->update($product_sale);
            }
            elseif( $product_sale['variant_id'] === null && (in_array($pro_id, $old_product_id)) ) {
                Product_Sale::where([
                    ['sale_id', $id],
                    ['product_id', $pro_id]
                ])->update($product_sale);
            }
            else
                Product_Sale::create($product_sale);
        }
        $lims_sale_data->update($data);
        $lims_customer_data = Customer::find($data['customer_id']);
        $message = 'Sale updated successfully';
        //collecting mail data
        if($lims_customer_data->email){
            $mail_data['email'] = $lims_customer_data->email;
            $mail_data['reference_no'] = $lims_sale_data->reference_no;
            $mail_data['sale_status'] = $lims_sale_data->sale_status;
            $mail_data['payment_status'] = $lims_sale_data->payment_status;
            $mail_data['total_qty'] = $lims_sale_data->total_qty;
            $mail_data['total_price'] = $lims_sale_data->total_price;
            $mail_data['order_tax'] = $lims_sale_data->order_tax;
            $mail_data['order_tax_rate'] = $lims_sale_data->order_tax_rate;
            $mail_data['order_discount'] = $lims_sale_data->order_discount;
            $mail_data['shipping_cost'] = $lims_sale_data->shipping_cost;
            $mail_data['grand_total'] = $lims_sale_data->grand_total;
            $mail_data['paid_amount'] = $lims_sale_data->paid_amount;

            if($mail_data['email']){
                try{
                    Mail::send( 'mail.sale_details', $mail_data, function( $message ) use ($mail_data)
                    {
                        $message->to( $mail_data['email'] )->subject( 'Sale Details' );
                    });
                }
                catch(\Exception $e){
                    $message = 'Sale updated successfully. Please setup your <a href="setting/mail_setting">mail setting</a> to send mail.';
                }
            }
        }

        return redirect('sales')->with('message', $message);
    }

    
    public function destroy($id)
    {
        $url = url()->previous();
        $lims_sale_data = Sale::find($id);
        $lims_product_sale_data = Product_Sale::where('sale_id', $id)->get();
        $lims_delivery_data = Delivery::where('sale_id',$id)->first();
        if($lims_sale_data->sale_status == 3)
            $message = 'Draft deleted successfully';
        else
            $message = 'Sale deleted successfully';

        foreach ($lims_product_sale_data as $product_sale) {
            $lims_product_data = Product::find($product_sale->product_id);
            //adjust product quantity
            if( ($lims_sale_data->sale_status == 1) && ($lims_product_data->type == 'combo') ) {
                $product_list = explode(",", $lims_product_data->product_list);
                $variant_list = explode(",", $lims_product_data->variant_list);
                $qty_list = explode(",", $lims_product_data->qty_list);
                if($lims_product_data->variant_list)
                    $variant_list = explode(",", $lims_product_data->variant_list);
                else
                    $variant_list = [];
                foreach ($product_list as $index=>$child_id) {
                    $child_data = Product::find($child_id);
                    if(count($variant_list) && $variant_list[$index]) {
                        $child_product_variant_data = ProductVariant::where([
                            ['product_id', $child_id],
                            ['variant_id', $variant_list[$index] ]
                        ])->first();

                        $child_warehouse_data = Product_Warehouse::where([
                            ['product_id', $child_id],
                            ['variant_id', $variant_list[$index] ],
                            ['warehouse_id', $lims_sale_data->warehouse_id ],
                        ])->first();

                         $child_product_variant_data->qty += $product_sale->qty * $qty_list[$index];
                         $child_product_variant_data->save();
                    }
                    else {
                        $child_warehouse_data = Product_Warehouse::where([
                            ['product_id', $child_id],
                            ['warehouse_id', $lims_sale_data->warehouse_id ],
                        ])->first();
                    }

                    $child_data->qty += $product_sale->qty * $qty_list[$index];
                    $child_warehouse_data->qty += $product_sale->qty * $qty_list[$index];

                    $child_data->save();
                    $child_warehouse_data->save();
                }
            }
            elseif(($lims_sale_data->sale_status == 1) && ($product_sale->sale_unit_id != 0)) {
                $lims_sale_unit_data = Unit::find($product_sale->sale_unit_id);
                if ($lims_sale_unit_data->operator == '*')
                    $product_sale->qty = $product_sale->qty * $lims_sale_unit_data->operation_value;
                else
                    $product_sale->qty = $product_sale->qty / $lims_sale_unit_data->operation_value;
                if($product_sale->variant_id) {
                    $lims_product_variant_data = ProductVariant::select('id', 'qty')->FindExactProduct($lims_product_data->id, $product_sale->variant_id)->first();
                    $lims_product_warehouse_data = Product_Warehouse::FindProductWithVariant($lims_product_data->id, $product_sale->variant_id, $lims_sale_data->warehouse_id)->first();
                    $lims_product_variant_data->qty += $product_sale->qty;
                    $lims_product_variant_data->save();
                }
                elseif($product_sale->product_batch_id) {
                    $lims_product_batch_data = ProductBatch::find($product_sale->product_batch_id);
                    $lims_product_warehouse_data = Product_Warehouse::where([
                        ['product_batch_id', $product_sale->product_batch_id],
                        ['warehouse_id', $lims_sale_data->warehouse_id]
                    ])->first();

                    $lims_product_batch_data->qty -= $product_sale->qty;
                    $lims_product_batch_data->save();
                }
                else {
                    $lims_product_warehouse_data = Product_Warehouse::FindProductWithoutVariant($lims_product_data->id, $lims_sale_data->warehouse_id)->first();
                }
                    
                $lims_product_data->qty += $product_sale->qty;
                $lims_product_warehouse_data->qty += $product_sale->qty;
                $lims_product_data->save();
                $lims_product_warehouse_data->save();
            }
            if($product_sale->imei_number) {
                if($lims_product_warehouse_data->imei_number)
                    $lims_product_warehouse_data->imei_number .= ',' . $product_sale->imei_number;
                else
                    $lims_product_warehouse_data->imei_number = $product_sale->imei_number;
                $lims_product_warehouse_data->save();
            }
            $product_sale->delete();
        }

        $lims_payment_data = Payment::where('sale_id', $id)->get();
        foreach ($lims_payment_data as $payment) {
            if($payment->paying_method == 'Gift Card'){
                $lims_payment_with_gift_card_data = PaymentWithGiftCard::where('payment_id', $payment->id)->first();
                $lims_gift_card_data = GiftCard::find($lims_payment_with_gift_card_data->gift_card_id);
                $lims_gift_card_data->expense -= $payment->amount;
                $lims_gift_card_data->save();
                $lims_payment_with_gift_card_data->delete();
            }
            elseif($payment->paying_method == 'Cheque'){
                $lims_payment_cheque_data = PaymentWithCheque::where('payment_id', $payment->id)->first();
                $lims_payment_cheque_data->delete();
            }
            elseif($payment->paying_method == 'Credit Card'){
                $lims_payment_with_credit_card_data = PaymentWithCreditCard::where('payment_id', $payment->id)->first();
                $lims_payment_with_credit_card_data->delete();
            }
            elseif($payment->paying_method == 'Paypal'){
                $lims_payment_paypal_data = PaymentWithPaypal::where('payment_id', $payment->id)->first();
                if($lims_payment_paypal_data)
                    $lims_payment_paypal_data->delete();
            }
            elseif($payment->paying_method == 'Deposit'){
                $lims_customer_data = Customer::find($lims_sale_data->customer_id);
                $lims_customer_data->expense -= $payment->amount;
                $lims_customer_data->save();
            }
            $payment->delete();
        }
        if($lims_delivery_data)
            $lims_delivery_data->delete();
        if($lims_sale_data->coupon_id) {
            $lims_coupon_data = Coupon::find($lims_sale_data->coupon_id);
            $lims_coupon_data->used -= 1;
            $lims_coupon_data->save();
        }
        $lims_sale_data->delete();
        return Redirect::to($url)->with('not_permitted', $message);
    }
}
