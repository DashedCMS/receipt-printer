<?php

namespace Dashed\ReceiptPrinter;

use Exception;
use Mike42\Escpos\Printer;
use Mike42\Escpos\EscposImage;
use Mike42\Escpos\CapabilityProfile;
use Dashed\ReceiptPrinter\Item as Item;
use Dashed\ReceiptPrinter\Store as Store;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedTranslations\Models\Translation;
use Mike42\Escpos\PrintConnectors\CupsPrintConnector;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;

class ReceiptPrinter
{
    private $printer;
    private $logo;
    private $store;
    private $items;
    private $currency = '€';
    private $subtotal = 0;
    private $tax_percentage = 10;
    private $tax = 0;
    private $grandtotal = 0;
    private $request_amount = 0;
    private $qr_code = [];
    private $transaction_id = '';
    private $order;

    public function __construct()
    {
        $this->printer = null;
        $this->items = [];
    }

    public function init($connector_type, $connector_descriptor, $connector_port = 9100)
    {
        switch (strtolower($connector_type)) {
            case 'cups':
                $connector = new CupsPrintConnector($connector_descriptor);

                break;
            case 'windows':
                $connector = new WindowsPrintConnector($connector_descriptor);

                break;
            case 'network':
                $connector = new NetworkPrintConnector($connector_descriptor);

                break;
            default:
                $connector = new FilePrintConnector("php://stdout");

                break;
        }

        if ($connector) {
            // Load simple printer profile
            $profile = CapabilityProfile::load("default");
            // Connect to printer
            $this->printer = new Printer($connector, $profile);
        } else {
            throw new Exception('Invalid printer connector type. Accepted values are: cups');
        }
    }

    public function close()
    {
        if ($this->printer) {
            $this->printer->close();
        }
    }

    public function setStore($name, $address, $phone, $email, $website)
    {
        $this->store = new Store($name, $address, $phone, $email, $website);
    }

    public function setOrder(Order $order)
    {
        $this->order = $order;
    }

    public function setLogo($logo)
    {
        $this->logo = $logo;
    }

    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    public function addItem($name, $qty, $price)
    {
        $item = new Item($name, $qty, $price);
        $item->setCurrency($this->currency);

        $this->items[] = $item;
    }

    public function setRequestAmount($amount)
    {
        $this->request_amount = $amount;
    }

    public function setTax($tax)
    {
        $this->tax_percentage = $tax;

        if ($this->subtotal == 0) {
            $this->calculateSubtotal();
        }

        $this->tax = (int)$this->tax_percentage / 100 * (int)$this->subtotal;
    }

    public function calculateSubtotal()
    {
        $this->subtotal = 0;

        foreach ($this->items as $item) {
            $this->subtotal += (int)$item->getQty() * (int)$item->getPrice();
        }
    }

    public function calculateGrandTotal()
    {
        if ($this->subtotal == 0) {
            $this->calculateSubtotal();
        }

        $this->grandtotal = (int)$this->subtotal + (int)$this->tax;
    }

    public function setTransactionID($transaction_id)
    {
        $this->transaction_id = $transaction_id;
    }

    public function setQRcode($content)
    {
        $this->qr_code = $content;
    }

    public function setTextSize($width = 1, $height = 1)
    {
        if ($this->printer) {
            $width = ($width >= 1 && $width <= 8) ? (int)$width : 1;
            $height = ($height >= 1 && $height <= 8) ? (int)$height : 1;
            $this->printer->setTextSize($width, $height);
        }
    }

    public function getPrintableQRcode()
    {
        return json_encode($this->qr_code);
    }

    public function getPrintableHeader($left_text, $right_text, $is_double_width = false)
    {
        $cols_width = $is_double_width ? 8 : 16;

        return str_pad($left_text, $cols_width) . str_pad($right_text, $cols_width, ' ', STR_PAD_LEFT);
    }

    public function getPrintableSummary($label, $value, $is_double_width = false, $format = true)
    {
        $left_cols = $is_double_width ? 6 : 12;
        $right_cols = $is_double_width ? 19 : 36;

        if ($format) {
            $value = $this->currency . number_format($value, 2, ',', '.');
        }

        return str_pad($label, $left_cols) . str_pad($value, $right_cols, ' ', STR_PAD_LEFT);
    }

    public function feed($feed = null)
    {
        $this->printer->feed($feed);
    }

    public function cut()
    {
        $this->printer->cut();
    }

    public function printDashedLine($total = 47)
    {
        $line = '';

        for ($i = 0; $i < $total; $i++) {
            $line .= '-';
        }

        $this->printer->text($line);
    }

    public function printLogo($mode = 0)
    {
        if ($this->logo) {
            $this->printImage($this->logo, $mode);
        }
    }

    public function printImage($image_path, $mode = 0)
    {
        if ($this->printer && $image_path) {
            $image = EscposImage::load($image_path, false);

            $this->printer->feed();

            switch ($mode) {
                case 0:
                    $this->printer->graphics($image);

                    break;
                case 1:
                    $this->printer->bitImage($image);

                    break;
                case 2:
                    $this->printer->bitImageColumnFormat($image);

                    break;
            }

            $this->printer->feed();
        }
    }

    public function printQRcode()
    {
        if (! empty($this->qr_code)) {
            $this->printer->qrCode($this->getPrintableQRcode(), Printer::BARCODE_TEXT_BELOW, 8);
        }
    }

    public function printBarcode()
    {
        if (! empty($this->qr_code)) {
            $this->printer->barcode('{B' . $this->qr_code, Printer::BARCODE_CODE128);
        }
    }

    public function openDrawer($pin = 0, $on_duration = 120, $off_duration = 240)
    {
        if ($this->printer) {
            $this->printer->pulse($pin, $on_duration, $off_duration);
        }
    }

    public function printReceipt($isCopy = false)
    {
        if ($this->printer && $this->order) {
            // Init printer settings
            $this->printer->initialize();
            $this->printer->selectPrintMode();
            // Set margins
            $this->printer->setPrintLeftMargin(1);
            // Print receipt headers
            $this->printer->setJustification(Printer::JUSTIFY_CENTER);
            // Image print mode
            $image_print_mode = 0; // 0 = auto; 1 = mode 1; 2 = mode 2
            // Print logo
            $this->printLogo($image_print_mode);
            $this->printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH);
            $this->printer->feed(2);
            $this->printer->text("{$this->store->getName()}\n");
            $this->printer->selectPrintMode();
            $this->printer->text(Customsetting::get('company_street') . ' ' . Customsetting::get('company_street_number') . "\n");
            $this->printer->text(Customsetting::get('company_postal_code') . ' ' . Customsetting::get('company_city') . "\n");
            $this->feed(2);

            // Print receipt title
            $this->printer->setEmphasis(true);
            $this->printer->text(($isCopy ? Translation::get('receipt-copy', 'receipt', 'KOPIE BON') : Translation::get('receipt', 'receipt', 'BON')) . "\n");
            $this->printer->setEmphasis(false);
            $this->printer->feed();

            $this->printer->setJustification(Printer::JUSTIFY_CENTER);
            $this->printer->text(Translation::get('transaction_id', 'receipt', 'Transactie ID:') . ' #' . $this->order->invoice_id, false, false) . "\n";
            $this->printer->setJustification(Printer::JUSTIFY_LEFT);
            $this->printer->feed(2);
            // Print items
            $this->printer->setJustification(Printer::JUSTIFY_LEFT);
            $productCount = count($this->order->orderProducts);
            foreach ($this->order->orderProducts as $orderProduct) {
                $this->printer->text(new \Dashed\ReceiptPrinter\Item($orderProduct->name, $orderProduct->quantity, $orderProduct->price / $orderProduct->quantity));
                if ($productCount > 1) {
                    $this->printDashedLine();
                }
                $productCount--;
            }
            $this->printer->feed(2);

            $this->printer->setEmphasis(true);
            $this->printer->text($this->getPrintableSummary(Translation::get('subtotal', 'receipt', 'Subtotaal'), $this->order->subtotal));
            $this->printer->setEmphasis(false);
            $this->printer->feed();

            $this->printDashedLine();
            foreach ($this->order->vat_percentages ?: [] as $vat_percentage => $vat_amount) {
                $this->printer->text($this->getPrintableSummary(Translation::get('tax-percentage', 'receipt', 'BTW') . ' ' . $vat_percentage . '%', $vat_amount) . "\n");
            }
            $this->printer->text($this->getPrintableSummary(Translation::get('tax-total', 'receipt', 'BTW totaal'), $this->order->btw) . "\n");
            $this->printDashedLine();
            $this->printer->feed();

            foreach ($this->order->orderPayments as $orderPayment) {
                $this->printer->text($this->getPrintableSummary($orderPayment->payment_method, $orderPayment->amount) . "\n");
            }
            $this->printDashedLine();
            $this->printer->feed(2);

            if ($this->order->discount > 0) {
                $this->printer->setEmphasis(true);
                $this->printer->text($this->getPrintableSummary(Translation::get('discount', 'receipt', 'Korting'), $this->order->discount));
                $this->printer->setEmphasis(false);
                $this->printer->feed(2);
            }

            $this->printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH);
            $this->printer->text($this->getPrintableSummary(Translation::get('total', 'receipt', 'Totaal'), $this->order->total, true) . "\n", true);
            $this->printer->selectPrintMode(Printer::MODE_EMPHASIZED);
            $this->printDashedLine();
            $this->printer->feed();

            $this->printer->selectPrintMode();
            // Print receipt footer
            $this->printer->feed();
            $this->printer->setJustification(Printer::JUSTIFY_CENTER);
            $this->printer->text(Translation::get('thanks-for-shopping', 'receipt', 'Bedankt voor je bezoek!'));
            $this->printer->feed();
            //            // Print receipt date
//            $this->printer->text(date('j F Y H:i:s'));
            $this->printer->text($this->order->created_at->format('j F Y H:i:s'));
            $this->printer->feed(2);
            $this->printer->text('Email: ' . Customsetting::get('site_to_email') . "\n");
            $this->printer->text('Webshop: ' . url('/') . "\n");
            $this->printer->text('Telefoon: ' . Customsetting::get('company_phone_number'));
            $this->printer->feed(2);
            // Print qr code
            $this->printBarcode();
            $this->printer->feed(2);
            // Cut the receipt
            $this->printer->cut();
            $this->printer->close();
        } else {
            throw new Exception('Printer has not been initialized.');
        }
    }

    public function printRequest()
    {
        if ($this->printer) {
            // Get request amount
            $total = $this->getPrintableSummary('TOTAL', $this->request_amount, true);
            $header = $this->getPrintableHeader(
                'TID: ' . $this->transaction_id
            );
            $footer = "This is not a proof of payment.\n";
            // Init printer settings
            $this->printer->initialize();
            $this->printer->feed();
            $this->printer->setJustification(Printer::JUSTIFY_CENTER);
            // Image print mode
            $image_print_mode = 0; // 0 = auto; 1 = mode 1; 2 = mode 2
            // Print logo
            $this->printLogo($image_print_mode);
            $this->printer->selectPrintMode();
            $this->printer->text("{$this->store->getName()}\n");
            $this->printer->text("{$this->store->getAddress()}\n");
            $this->printer->text($header . "\n");
            $this->printer->feed();
            // Print receipt title
            $this->printDashedLine();
            $this->printer->setEmphasis(true);
            $this->printer->text("PAYMENT REQUEST\n");
            $this->printer->setEmphasis(false);
            $this->printDashedLine();
            $this->printer->feed();
            // Print instruction
            $this->printer->text("Please scan the code below\nto make payment\n");
            $this->printer->feed();
            // Print qr code
            $this->printQRcode();
            $this->printer->feed();
            // Print grand total
            $this->printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH);
            $this->printer->text($total . "\n");
            $this->printer->feed();
            $this->printer->selectPrintMode();
            // Print receipt footer
            $this->printer->feed();
            $this->printer->setJustification(Printer::JUSTIFY_CENTER);
            $this->printer->text($footer);
            $this->printer->feed();
            // Print receipt date
            $this->printer->text($this->order->created_at->format('j F Y H:i:s'));
//            $this->printer->text(date('j F Y H:i:s'));
            $this->printer->feed(2);
            // Cut the receipt
            $this->printer->cut();
            $this->printer->close();
        } else {
            throw new Exception('Printer has not been initialized.');
        }
    }
}
