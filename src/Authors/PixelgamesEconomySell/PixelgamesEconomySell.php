<?php

/*
 * EconomyS, the massive economy plugin with many features for PocketMine-MP
 * Copyright (C) 2013-2017  onebone <jyc00410@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Authors\PixelgamesEconomySell;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;

use onebone\economyapi\EconomyAPI;

use Authors\PixelgamesEconomySell\provider\DataProvider;
use Authors\PixelgamesEconomySell\provider\YamlDataProvider;
use Authors\PixelgamesEconomySell\item\ItemDisplayer;
use Authors\PixelgamesEconomySell\event\SellCreationEvent;
use Authors\PixelgamesEconomySell\event\SellTransactionEvent;

class PixelgamesEconomySell extends PluginBase implements Listener{

    /**
     * @var DataProvider
     */

    private $provider;

    private $lang;

    private $queue = [], $tap = [], $removeQueue = [], $placeQueue = [];


    /** @var ItemDisplayer[][] */
    private $items = [];
    
public function onLoad() {
    $this->getLogger()->info("Laden...");
}

public function onEnable(){
        $this->saveDefaultConfig();
        
        $provider = $this->getConfig()->get("data-provider");
        switch(strtolower($provider)){

            case "yaml":
                $this->provider = new YamlDataProvider($this->getDataFolder() . "Sells.yml", $this->getConfig()->get("auto-save"));
                $this->getLogger()->info("Aktiviert");
                break;

            default:
                $this->getLogger()->critical("Der angegebene Datenprovider ist ungültig. PixelgamesEcomomySell wird beendet...");
                $this->getLogger()->info("Deaktivieren...");
                return;
        }

        $this->getLogger()->notice("Der Datenprovider wurde festgelegt auf: " . $this->provider->getProviderName());

$levels = [];

        foreach($this->provider->getAll() as $sell){
            if($sell[9] !== -2){

                if(!isset($levels[$sell[3]])){
                    $levels[$sell[3]] = $this->getServer()->getLevelByName($sell[3]);
                }

                $pos = new Position($sell[0], $sell[1], $sell[2], $levels[$sell[3]]);
                $display = $pos;

                if($sell[9] !== -1){
                    $display = $pos->getSide($sell[9]);
                }
                $this->items[$sell[3]][] = new ItemDisplayer($display, Item::get($sell[4], $sell[5]), $pos);
            }
        }

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

 public function onCommand(CommandSender $sender, Command $command, string $label, array $params) : bool{
        switch($command->getName()){

            case "sell":
                
                if (!isset($params[0])) {
                    $sender->sendMessage("§c[PGEconomySell] Benutzung: /sell <create|delete> [ItemID[:Meta]] [Menge] [Belohnung] [Ausrichtung]");
                    $sender->sendMessage("§6[PGEconomySell] Benutzung: /sell info");
                    $sender->sendMessage("§6[PGEconomySell] Benutzung: /sell help");
                }
                
                
                switch(strtolower(array_shift($params))){
                    
                    case "info":
                        $sender->sendMessage("§e---------------------------------");
                        $sender->sendMessage("§ePlugin von onebone, iStrafeNubzHDyt");
                        $sender->sendMessage("§bName: PixelgamesEconomySell");
                        $sender->sendMessage("§bOriginal: EconomyS\EconomySell");
                        $sender->sendMessage("§bVersion: 2.5#");
                        $sender->sendMessage("§bFür PocketMine-API: 3.0.0-ALPHA12");
                        $sender->sendMessage("§6Permissions: pgeconomysell, pgeconomysell.command.sell, pgeconomysell.command.sell.create, pgeconomysell.command.sell.delete, pgeconomysell.sell.*, pgeconomysell.sell.sell");
                        $sender->sendMessage("§eSpeziell für PIXELGAMES entwickelt");
                        $sender->sendMessage("§e---------------------------------");
                        return true;
                        
                        
                    case "help":
                        $sender->sendMessage("§9---§aSell-Plugin§9---");
                        $sender->sendMessage("§a/sell <create/cr/c/add> <ItemID[:Meta]> <Menge> <Belohnung> [Ausrichtung] §b-> Erstellt einen Verkaufsstand (Warteschlange)");
                        $sender->sendMessage("§a/sell <create/cr/c> §b-> Beendet die Warteschlange zum Erstellen");
                        $sender->sendMessage("§a/sell <delete/del/d/remove/rm/r> §b-> Löscht einen Verkaufsstand (Warteschlange) oder beendet die Warteschlange zum Löschen");
                        $sender->sendMessage("§6/sell info §b-> Zeigt Details über das Plugin");
                        $sender->sendMessage("§6/sell help §b-> Zeigt dieses Hilfemenü an");
                        $sender->sendMessage("§6Durch Antippen eines Verkaufsstandes (normalerweise als Schild) kann man seine Items gegen Spielgeld eintauschen und somit einen Verkauf durchführen.");
                        return true;
                        
                                
                    case "create":
                    case "cr":
                    case "c":
                    case "add":

                        if(!$sender instanceof Player){
                            $sender->sendMessage(TextFormat::DARK_RED . "[PGEconomySell] Dieser Befehl muss ingame ausgeführt werden");
                            return true;
                        }

                        if(!$sender->hasPermission("pgeconomysell.command.sell.create")){
                            $sender->sendMessage(TextFormat::RED . "[PGEconomySell] Du hast nicht die Berechtigung, diesen Befehl auszuführen!");
                            return true;
                        }

                        if(isset($this->queue[strtolower($sender->getName())])){
                            unset($this->queue[strtolower($sender->getName())]);
                            $sender->sendMessage("§6[PGEconomySell] Der Auftrag zur Erstellung eines Verkaufsstandes wurde beendet");
                            return true;
                        }

                        $item = array_shift($params);
                        $amount = array_shift($params);
                        $price = array_shift($params);
                        $side = array_shift($params);

                        if(trim($item) === "" or trim($amount) === "" or trim($price) === "" or !is_numeric($amount) or !is_numeric($price)){
                            $sender->sendMessage("§c[PGEconomySell] Benutzung: /sell create <ItemID[:Meta]> <Menge> <Belohnung> [Ausrichtung]");
                            return true;
                        }

                        

                            switch(strtolower($side)){

                                case "up":
                                case Vector3::SIDE_UP:
                                    $side = Vector3::SIDE_UP;
                                    break;

                                case "down":
                                case Vector3::SIDE_DOWN:
                                    $side = Vector3::SIDE_DOWN;
                                    break;

                                case "west":
                                case Vector3::SIDE_WEST:
                                    $side = Vector3::SIDE_WEST;
                                    break;

                                case "east":
                                case Vector3::SIDE_EAST:
                                    $side = Vector3::SIDE_EAST;
                                    break;

                                case "north":
                                case Vector3::SIDE_NORTH:
                                    $side = Vector3::SIDE_NORTH;
                                    break;

                                case "south":
                                case Vector3::SIDE_SOUTH:
                                    $side = Vector3::SIDE_SOUTH;
                                    break;

                                case "sell":
                                case -1:
                                    $side = -1;
                                    break;

                                case "none":
                                case -2:
                                    $side = -2;
                                    break;

                                default:
                                    $sender->sendMessage("§c[PGEconomySell] Fehler: Ungültige Option bei der Ausrichtung");
                                    return true;
                            }
                        

                        $this->queue[strtolower($sender->getName())] = [
                            $item, (int)$amount, $price, (int)$side
                        ];

                        $sender->sendMessage("§2[PGEconomySell] Verkaufsstand in der Warteschlange.  Zum Erstellen einen Block antippen!");
                        return true;

                        
                    case "remove":
                    case "rm":
                    case "r":
                    case "delete":
                    case "del":
                    case "d":

                        if(!$sender instanceof Player){
                            $sender->sendMessage(TextFormat::DARK_RED . "[PGEconomySell] Dieser Befehl muss ingame ausgeführt werden");
                            return true;
                        }

                        if(!$sender->hasPermission("pgeconomysell.command.sell.remove")){
                            $sender->sendMessage(TextFormat::RED . "[PGEconomySell] Du hast nicht die Berechtigung, diesen Befehl auszuführen!");
                            return true;
                        }

                        if(isset($this->removeQueue[strtolower($sender->getName())])){
                            unset($this->removeQueue[strtolower($sender->getName())]);
                            $sender->sendMessage("§6[PGEconomySell] Der Auftrag zur Schließung eines Verkaufsstandes wurde beendet");
                            return true;
                        }

                        $this->removeQueue[strtolower($sender->getName())] = true;
                        $sender->sendMessage("§2[PGEconomySell] Verkaufsstand wird entfernt. Zum Löschen einen Block antippen!");
                        return true;
                }
        }
        return true;
    }

 public function onPlayerJoin(PlayerJoinEvent $event){

        $player = $event->getPlayer();
        $level = $player->getLevel()->getFolderName();

        if(isset($this->items[$level])){
            foreach($this->items[$level] as $displayer){
                $displayer->spawnTo($player);
            }
        }
    }
    
    public function onPlayerTeleport(EntityTeleportEvent $event){

        $player = $event->getEntity();
        if($player instanceof Player){
            if(($from = $event->getFrom()->getLevel()) !== ($to = $event->getTo()->getLevel())){
                if($from !== null and isset($this->items[$from->getFolderName()])){
                    foreach($this->items[$from->getFolderName()] as $displayer){
                        $displayer->despawnFrom($player);
                    }
                }

                if($to !== null and isset($this->items[$to->getFolderName()])){
                    foreach($this->items[$to->getFolderName()] as $displayer){
                        $displayer->spawnTo($player);
                    }
                }
            }
        }
    }
    
    public function onBlockTouch(PlayerInteractEvent $event){

        if($event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK){
            return;
        }


        $player = $event->getPlayer();
        $block = $event->getBlock();

        $iusername = strtolower($player->getName());


        if(isset($this->queue[$iusername])){
            $queue = $this->queue[$iusername];
            $item = Item::fromString($queue[0]);
            $item->setCount($queue[1]);

            $ev = new SellCreationEvent($block, $item, $queue[2], $queue[3]);
            $this->getServer()->getPluginManager()->callEvent($ev);


            if($ev->isCancelled()){
                $player->sendMessage("§4[PGEconomySell] Aufgrund eines unbekannten Fehlers wurde die Erstellung des Verkaufsstandes abgebrochen");
                unset($this->queue[$iusername]);
                return;
            }

            $result = $this->provider->addSell($block, [
                $block->getX(), $block->getY(), $block->getZ(), $block->getLevel()->getFolderName(),
                $item->getID(), $item->getDamage(), $item->getName(), $queue[1], $queue[2], $queue[3]
            ]);


            if($result){
                if($queue[3] !== -2){
                    $pos = $block;
                    
                    if($queue[3] !== -1){
                        $pos = $block->getSide($queue[3]);
                    }

                    $this->items[$pos->getLevel()->getFolderName()][] = ($dis = new ItemDisplayer($pos, $item, $block));
                    $dis->spawnToAll($pos->getLevel());
                }

                $player->sendMessage("§a[PGEconomySell] Verkaufsstand erfolgreich erstellt");

            }else{
                $player->sendMessage("§c[PGEconomySell] Hier befindet sich bereits ein Verkaufsstand!");
            }

            if($event->getItem()->canBePlaced()){
                $this->placeQueue[$iusername] = true;
            }

            unset($this->queue[$iusername]);
            return;

        }elseif(isset($this->removeQueue[$iusername])){
            $sell = $this->provider->getSell($block);

            foreach($this->items as $level => $arr){
                foreach($arr as $key => $displayer){
                    $link = $displayer->getLinked();

                    if($link->getX() === $sell[0] and $link->getY() === $sell[1] and $link->getZ() === $sell[2] and $link->getLevel()->getFolderName() === $sell[3]){
                        $displayer->despawnFromAll();
                        unset($this->items[$key]);
                        break 2;
                    }
                }
            }

 $this->provider->removeSell($block);

            unset($this->removeQueue[$iusername]);
            $player->sendMessage("§a[PGEconomySell] Verkaufsstand erfolgreich entfernt");

            if($event->getItem()->canBePlaced()){
                $this->placeQueue[$iusername] = true;
            }
            return;
        }
        
         if(($sell = $this->provider->getSell($block)) !== false){
            if($this->getConfig()->get("enable-double-tap")){
                $now = time();

                if(isset($this->tap[$iusername]) and $now - $this->tap[$iusername] < 1){
                    $this->sellItem($player, $sell);
                    unset($this->tap[$iusername]);

                }else{
                    $this->tap[$iusername] = $now;
                    $player->sendMessage ("§e[PGEconomySell] Tippe doppelt, um den Verkauf zu bestätigen!", [$sell[6], $sell[7], $sell[8]]);
                }

            }else{
                $this->sellItem($player, $sell);
            }

            if($event->getItem()->canBePlaced()){
                $this->placeQueue[$iusername] = true;
            }
        }
    }
    
     public function onBlockPlace(BlockPlaceEvent $event){
        $iusername = strtolower($event->getPlayer()->getName());

        if(isset($this->placeQueue[$iusername])){
            $event->setCancelled();
            unset($this->placeQueue[$iusername]);
        }
    }
    
    public function onBlockBreak(BlockBreakEvent $event){
        $block = $event->getBlock();

        if($this->provider->getSell($block) !== false){
            $player = $event->getPlayer();

            $event->setCancelled(true);
            $player->sendMessage("§c[PGEconomySell] Du kannst keine Verkaufsstände zerstören!");
        }
    }

 private function sellItem(Player $player, $sell){

        if(!$player instanceof Player){
            return false;
        }

        if(!$player->hasPermission("pgeconomysell.sell.sell")){
            $player->sendMessage("§4[PGEconomySell] Du hast nicht die Berechtigung, Items zu verkaufen!");
            return false;
        }

        if(is_string($sell[4])){
            $itemId = ItemFactory::fromString($sell[4], false)->getId();

        }else{
            $itemId = ItemFactory::get((int)$sell[4], false)->getId();
        }

        $item = ItemFactory::get($itemId, (int)$sell[5], (int)$sell[7]);

        if($player->getInventory()->contains($item)){
            $ev = new SellTransactionEvent($player, new Position($sell[0], $sell[1], $sell[2], $this->getServer()->getLevelByName($sell[3])), $item, $sell[8]);
            $this->getServer()->getPluginManager()->callEvent($ev);

            if($ev->isCancelled()){
                $player->sendMessage("§4[PGEconomySell] Aufgrund eines unbekannten Fehlers ist der Verkauf fehlgeschlagen");
                return true;
            }

            $player->getInventory()->removeItem($item);
            $player->sendMessage("§a[PGEconomySell] Du hast erfolgreich verkauft und Geld erhalten (siehe Infos auf dem Schild)", [$sell[6], $sell[7], $sell[8]]);
            EconomyAPI::getInstance()->addMoney($player, $sell[8]);

        }else{
            $player->sendMessage("§c[PGEconomySell] Du hast nicht genug Items für diesen Verkauf", [$sell[6]]);
        }
        return true;
    }
    
    public function sendMessage($key, $replacement = []) {
        $key = strtolower($key);
        
        if(isset($this->lang[$key])){
            $search = [];
            $replace = [];
            $this->replaceColors($search, $replace);


            $search[] = "%MONETARY_UNIT%";
            $replace[] = EconomyAPI::getInstance()->getMonetaryUnit();
            
            
            for($i = 1; $i <= count($replacement); $i++){
                $search[] = "%" . $i;
                $replace[] = $replacement[$i - 1];
            }
            return str_replace($search, $replace, $this->lang[$key]);
        }
        return "Could not find \"$key\".";
    }
            
            
            
     public function getMessage($key, $replacement = []){
        $key = strtolower($key);

        if(isset($this->lang[$key])){
            $search = [];
            $replace = [];
            $this->replaceColors($search, $replace);


            $search[] = "%MONETARY_UNIT%";
            $replace[] = EconomyAPI::getInstance()->getMonetaryUnit();


            for($i = 1; $i <= count($replacement); $i++){
                $search[] = "%" . $i;
                $replace[] = $replacement[$i - 1];
            }
            return str_replace($search, $replace, $this->lang[$key]);
        }
        return "Could not find \"$key\".";
    }
    
      private function replaceColors(&$search = [], &$replace = []){

        $colors = [
            "BLACK" => "0",
            "DARK_BLUE" => "1",
            "DARK_GREEN" => "2",
            "DARK_AQUA" => "3",
            "DARK_RED" => "4",
            "DARK_PURPLE" => "5",
            "GOLD" => "6",
            "GRAY" => "7",
            "DARK_GRAY" => "8",
            "BLUE" => "9",
            "GREEN" => "a",
            "AQUA" => "b",
            "RED" => "c",
            "LIGHT_PURPLE" => "d",
            "YELLOW" => "e",
            "WHITE" => "f",
            "OBFUSCATED" => "k",
            "BOLD" => "l",
            "STRIKETHROUGH" => "m",
            "UNDERLINE" => "n",
            "ITALIC" => "o",
            "RESET" => "r"
        ];

        foreach($colors as $color => $code){
            
            $search[] = "%%" . $color . "%%";
            $search[] = "&" . $code;

            $replace[] = TextFormat::ESCAPE . $code;
            $replace[] = TextFormat::ESCAPE . $code;
        }
    }


    public function onDisable(){
        $this->getLogger()->info("Deaktiviert");
        
        if($this->provider instanceof DataProvider){
            $this->provider->close();
        }
    }
}
