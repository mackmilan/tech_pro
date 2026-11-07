#!/bin/bash
set -e

echo "Inicializando bucket S3 no LocalStack..."
awslocal s3 mb s3://infocell 2>/dev/null || true
echo "Bucket S3 infocell pronto!"

