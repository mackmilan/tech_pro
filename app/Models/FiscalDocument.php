<?php

namespace App\Models;

class FiscalDocument extends BaseModel
{
    protected $fillable = ['sale_id', 'model', 'status', 'access_key', 'protocol', 'xml_path', 'pdf_path', 'message'];
}
