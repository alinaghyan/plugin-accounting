<?php
defined( 'ABSPATH' ) || exit;

/**
 * Append-only stock ledger. Positive quantities are receipts; negative are issues.
 * Quantities use an explicit integer scale (default: 1,000,000 units).
 */
final class MDA_Inventory_Service {
 private const MOVEMENT_TYPES = array('opening','purchase_receipt','sale_issue','customer_return','supplier_return','adjustment_in','adjustment_out','transfer_in','transfer_out','damage_out','reversal');

 public static function warehouses(): array {
  return MDA_Database::connection()->query("SELECT id,warehouse_code,warehouse_name,is_active FROM mda_warehouses ORDER BY id")->fetchAll();
 }

 public static function create_warehouse(string $code,string $name): int {
  $code=strtoupper(trim(sanitize_key($code)));$name=trim(sanitize_text_field($name));
  if(''===$code||''===$name) throw new InvalidArgumentException('کد و نام انبار الزامی است.');
  $q=MDA_Database::connection()->prepare('INSERT INTO mda_warehouses (warehouse_code,warehouse_name,is_active,created_at) VALUES (:code,:name,1,:now)');
  $q->execute(array(':code'=>$code,':name'=>$name,':now'=>gmdate('Y-m-d H:i:s')));
  return (int)MDA_Database::connection()->lastInsertId();
 }

 public static function record_movement(array $movement): array {
  $product=isset($movement['product_id'])?(int)$movement['product_id']:0;
  $variation=isset($movement['variation_id'])?(int)$movement['variation_id']:0;
  $warehouse=isset($movement['warehouse_id'])?(int)$movement['warehouse_id']:0;
  $quantity=$movement['quantity_scaled']??null;
  $scale=isset($movement['quantity_scale'])?(int)$movement['quantity_scale']:1000000;
  $type=isset($movement['movement_type'])?sanitize_key($movement['movement_type']):'';
  $key=isset($movement['idempotency_key'])?trim((string)$movement['idempotency_key']):'';
  $sourceType=isset($movement['source_type'])?sanitize_key($movement['source_type']):null;
  $sourceId=isset($movement['source_id'])?sanitize_text_field((string)$movement['source_id']):null;
  $cost=$movement['unit_cost_minor']??null;
  if($product<1||$variation<0||$warehouse<1||!is_int($quantity)||0===$quantity||$scale<1||$scale>1000000000||!in_array($type,self::MOVEMENT_TYPES,true)||strlen($key)<8||strlen($key)>191) throw new InvalidArgumentException('اطلاعات گردش انبار معتبر نیست.');
  if(null!==$cost&&(!is_int($cost)||$cost<0)) throw new InvalidArgumentException('بهای واحد باید عدد صحیح نامنفی باشد.');
  if(in_array($type,array('opening','purchase_receipt','customer_return','adjustment_in','transfer_in'),true)&&$quantity<0) throw new InvalidArgumentException('مقدار ورود باید مثبت باشد.');
  if(in_array($type,array('sale_issue','supplier_return','adjustment_out','transfer_out','damage_out'),true)&&$quantity>0) throw new InvalidArgumentException('مقدار خروج باید منفی باشد.');
  $now=isset($movement['occurred_at'])?(string)$movement['occurred_at']:gmdate('Y-m-d H:i:s');
  $currency=isset($movement['currency_code'])?strtoupper((string)$movement['currency_code']):'IRR';
  $payloadHash=hash('sha256',wp_json_encode(array($product,$variation,$warehouse,$quantity,$scale,$type,$sourceType,$sourceId,$cost,$currency,$now)));
  return MDA_Database::transaction(static function(PDO $db) use($product,$variation,$warehouse,$quantity,$scale,$type,$key,$sourceType,$sourceId,$cost,$currency,$now,$payloadHash): array {
   $existing=$db->prepare('SELECT id,product_id,variation_id,warehouse_id,quantity_scaled,quantity_scale,movement_type,unit_cost_minor,currency_code,source_type,source_id,occurred_at FROM mda_inventory_movements WHERE idempotency_key=:key LIMIT 1');
   $existing->execute(array(':key'=>$key));$row=$existing->fetch();
   if($row){if((int)$row['product_id']!==$product||(int)$row['variation_id']!==$variation||(int)$row['warehouse_id']!==$warehouse||(int)$row['quantity_scaled']!==$quantity||(int)$row['quantity_scale']!==$scale||$row['movement_type']!==$type||$row['unit_cost_minor']!==$cost||$row['currency_code']!==$currency||$row['source_type']!==$sourceType||$row['source_id']!==$sourceId||$row['occurred_at']!==$now)throw new RuntimeException('کلید یکتا قبلاً برای گردش متفاوتی استفاده شده است.');return array('movement_id'=>(int)$row['id'],'duplicate'=>true);}
   $wh=$db->prepare('SELECT 1 FROM mda_warehouses WHERE id=:id AND is_active=1');$wh->execute(array(':id'=>$warehouse));if(!$wh->fetchColumn())throw new InvalidArgumentException('انبار وجود ندارد یا غیرفعال است.');
   $balance=$db->prepare('SELECT COALESCE(SUM(quantity_scaled),0) FROM mda_inventory_movements WHERE product_id=:product AND variation_id=:variation AND warehouse_id=:warehouse AND quantity_scale=:scale');
   $balance->execute(array(':product'=>$product,':variation'=>$variation,':warehouse'=>$warehouse,':scale'=>$scale));$current=(int)$balance->fetchColumn();
   if($quantity<0&&$current < -$quantity)throw new RuntimeException('موجودی انبار برای این خروج کافی نیست.');
   $insert=$db->prepare('INSERT INTO mda_inventory_movements (product_id,variation_id,warehouse_id,movement_type,quantity_scaled,quantity_scale,unit_cost_minor,currency_code,source_type,source_id,idempotency_key,occurred_at,created_by) VALUES (:product,:variation,:warehouse,:type,:quantity,:scale,:cost,:currency,:source_type,:source_id,:key,:occurred,:user)');
   $insert->execute(array(':product'=>$product,':variation'=>$variation,':warehouse'=>$warehouse,':type'=>$type,':quantity'=>$quantity,':scale'=>$scale,':cost'=>$cost,':currency'=>$currency,':source_type'=>$sourceType,':source_id'=>$sourceId,':key'=>$key,':occurred'=>$now,':user'=>get_current_user_id()?:null));
   return array('movement_id'=>(int)$db->lastInsertId(),'duplicate'=>false,'balance_scaled'=>$current+$quantity);
  });
 }

 public static function balance(int $productId,int $warehouseId,int $variationId=0,int $scale=1000000): int {
  if($productId<1||$warehouseId<1||$variationId<0||$scale<1)throw new InvalidArgumentException('شناسه محصول، انبار یا مقیاس معتبر نیست.');
  $q=MDA_Database::connection()->prepare('SELECT COALESCE(SUM(quantity_scaled),0) FROM mda_inventory_movements WHERE product_id=:product AND variation_id=:variation AND warehouse_id=:warehouse AND quantity_scale=:scale');
  $q->execute(array(':product'=>$productId,':variation'=>$variationId,':warehouse'=>$warehouseId,':scale'=>$scale));return (int)$q->fetchColumn();
 }

 public static function cardex(int $productId,int $warehouseId,int $variationId=0,int $limit=200): array {
  $q=MDA_Database::connection()->prepare('SELECT id,movement_type,quantity_scaled,quantity_scale,unit_cost_minor,currency_code,source_type,source_id,occurred_at FROM mda_inventory_movements WHERE product_id=:product AND variation_id=:variation AND warehouse_id=:warehouse ORDER BY occurred_at DESC,id DESC LIMIT :limit');
  $q->bindValue(':product',$productId,PDO::PARAM_INT);$q->bindValue(':variation',$variationId,PDO::PARAM_INT);$q->bindValue(':warehouse',$warehouseId,PDO::PARAM_INT);$q->bindValue(':limit',max(1,min(500,$limit)),PDO::PARAM_INT);$q->execute();return $q->fetchAll();
 }
}
