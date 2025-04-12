# terraform-aws-epicyon

This Terraform plan contains deploying Epicyon on an AWS Lightsail instance

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
| [aws_lightsail_static_ip.epicyon_static_ip](https://registry.terraform.io/providers/hashicorp/aws/latest/docs/resources/lightsail_static_ip) | resource |
| [aws_lightsail_static_ip_attachment.for_epicyon](https://registry.terraform.io/providers/hashicorp/aws/latest/docs/resources/lightsail_static_ip_attachment) | resource |
| [aws_lightsail_key_pair.ssh_key_pair](https://registry.terraform.io/providers/hashicorp/aws/latest/docs/resources/lightsail_key_pair) | resource |
| [aws_lightsail_instance.epicyon](https://registry.terraform.io/providers/hashicorp/aws/latest/docs/resources/lightsail_instance) | resource |
| [aws_lightsail_domain.epicyon_domain](https://registry.terraform.io/providers/hashicorp/aws/latest/docs/resources/lightsail_domain) | resource |
| [aws_lightsail_domain_entry.epicyon](https://registry.terraform.io/providers/hashicorp/aws/latest/docs/resources/lightsail_domain_entry) | resource |
| [null_resource.null_resource_epicyon](https://registry.terraform.io/providers/hashicorp/null/latest/docs/resources/resource) | resource |

## Inputs

| Name | Description | Type | Default | Required |
|------|-------------|------|---------|:--------:|
| name | Name of instance. | `string` | `""` | yes |
| blueprint\_id | The ID for a virtual private server image | `string` | `"ubuntu_20_04"` | yes |
| bundle\_id | The bundle of specification information | `string` | `"nano_2_0"` | yes |
| availability\_zone | The Availability Zone in which to create your instance | `string` | `""` | yes |
| create\_static\_ip | Create and attach a statis IP to the instance | `` | `` | no |
| key_pair_name | Key pair name of the Key Pair to use for the instance | `string` | `""` | yes |
| domain | A public domain for Epicyon | `string` | `""` | yes |
| email | Email used to order a certificate from Let's Encrypt | `string` | `""` | yes |

## Output

| Name | Description |
| ---- | ----------- |
| domain_name | The URL to epicyon |
| ipv4_address | The public IP address of the epicyon instance |

