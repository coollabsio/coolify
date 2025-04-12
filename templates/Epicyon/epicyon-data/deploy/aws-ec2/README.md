# terraform-aws-epicyon

This repo contains a Terraform plan for deploying Epicyon on an AWS EC2 instance

## Requirements

| Name | Version |
| ---- | ------- |
| terraform | >=v1.0.7 |
| aws | ~> 4.0 |

## Providers

|Name | Version |
| --- | ------- |
| aws | ~> 4.0 |


## Resources

| Name | Type |
|------|------|
| [aws_eip.epicyon](https://registry.terraform.io/providers/hashicorp/aws/latest/docs/resources/eip) | resource |
| [aws_eip_association.epicyon](https://registry.terraform.io/providers/hashicorp/aws/latest/docs/resources/eip_association) | resource |
| [aws_iam_instance_profile.epicyon_instance_profile](https://registry.terraform.io/providers/hashicorp/aws/latest/docs/resources/iam_instance_profile) | resource |
| [aws_iam_policy_attachment.epicyon](https://registry.terraform.io/providers/hashicorp/aws/latest/docs/resources/iam_policy_attachment) | resource |
| [aws_iam_role.epicyon_iam_role](https://registry.terraform.io/providers/hashicorp/aws/latest/docs/resources/iam_role) | resource |
| [aws_instance.epicyon_web](https://registry.terraform.io/providers/hashicorp/aws/latest/docs/resources/instance) | resource |
| [aws_ami.ubuntu](https://registry.terraform.io/providers/hashicorp/aws/latest/docs/data-sources/ami) | data source |
| [aws_security_group.epicyon_sg](https://registry.terraform.io/providers/hashicorp/aws/latest/docs/resources/security_group) | resource |
| [aws_vpc.epicyon_vpc](https://registry.terraform.io/providers/hashicorp/aws/latest/docs/resources/vpc) | resource |
| [aws_subnet.epicyon_subnet](https://registry.terraform.io/providers/hashicorp/aws/latest/docs/resources/subnet) | resource |
| [aws_internet_gateway.epicyon_gw](https://registry.terraform.io/providers/hashicorp/aws/latest/docs/resources/internet_gateway) | resource |
| [aws_route_table.epicyon_route_table](https://registry.terraform.io/providers/hashicorp/aws/latest/docs/resources/route_table) | resource |
| [aws_route_table_association.epicyon_route_table_association](https://registry.terraform.io/providers/hashicorp/aws/latest/docs/resources/route_table_association) | resource |
| [aws_route53_record.epicyon_route53](https://registry.terraform.io/providers/hashicorp/aws/latest/docs/resources/route53_record) | resource |
| [null_resource.null_resource_epicyon](https://registry.terraform.io/providers/hashicorp/null/latest/docs/resources/resource) | resource |

## Inputs

| Name | Description | Type | Default | Required |
|------|-------------|------|---------|:--------:|
| vpc_cidr_block | The IPv4 CIDR block for the VPC | `number` | `""` | yes |
| subnet_cidr | The IPv4 CIDR block for the subnet | `number` | `""` | yes |
| route_cidr_block | The CIDR block of the route | `number` | `""` | yes |
| key_name | Key name of the Key Pair to use for the instance | `string` | `""` | yes |
| instance\_type | The instance type to use for the instance. | `string` | `"t2.micro"` | no |
| domain | A public domain for Epicyon | `string` | `""` | yes |
| email | Email used to order a certificate from Let's Encrypt | `string` | `""` | yes |

## Output

| Name | Description |
| ---- | ----------- |
| ipv4_address | The public IP address of the epicyon instance |
| domain_name | The URL to epicyon |
