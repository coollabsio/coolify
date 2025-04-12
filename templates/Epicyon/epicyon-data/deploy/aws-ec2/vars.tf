variable "vpc_cidr_block" {
  type    = number
  default = ""
}

variable "subnet_cidr" {
  type    = number
  default = ""
}

variable "route_cidr_block" {
  type    = number
  default = ""
}

variable "key_name" {
  type    = string
  default = ""
}

variable "instance_type" {
  type    = string
  default = "t2.micro"
}

variable "domain" {
  type    = string
  default = ""
}

variable "email" {
  type    = string
  default = ""
}

variable "private_key" {
  default = ""
}

variable "epicyon_domain" {
  default = ""
}

variable "email" {
  default = ""
}